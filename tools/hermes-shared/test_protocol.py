"""
Tests for the shared WedWorks chat protocol (tools/hermes-shared/wedworks_protocol.py).

The protocol is used by both the bridge and the Hermes plugin, so it must not import anything from Hermes.
It is the contract these tests describe:

    parse_plan(text)                      -> {"reply", "answers", "widget", "ready"} or None
    wrap_supplier_text(text)              -> the supplier's words as plain conversation (never a "/command")
    build_context(chat, problem=None)     -> the instructions, questions, collected answers and recent conversation
    apply_plan(api, chat_id, plan, snapshot_id, final=False) -> Outcome(status, problem)
        status: "sent" (reply posted), "stale" (the supplier wrote again; nothing posted),
                "problem" (the system refused something; ask Hermes once more, then call again with final=True)

`api(method, path, body=None)` returns (http_status, json_dict), like the bridge's own helper.

Run:  python -m unittest discover -s tools/hermes-shared
"""
import json
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import wedworks_protocol as wp  # noqa: E402


# ------------------------------------------------------------------ helpers

def chat(transcript=None, collected=None, bot='registration', questions=None):
    return {
        'chat_id': 'c' * 40,
        'bot': bot,
        'waiting_for_reply': True,
        'supplier': None,
        'guidelines': ['Ask one thing at a time.'],
        'questions': questions if questions is not None else [
            {'key': 'name', 'text': 'May I know your name?', 'required': True, 'fields': ['name'], 'rules': 'Required. Full name.', 'options': None},
            {'key': 'business_type', 'text': 'What type of service?', 'required': True, 'fields': ['business_type'],
             'rules': 'Required. One of the options.', 'options': [{'value': 'florist', 'label': 'Florist'}]},
        ],
        'collected': collected or {},
        'transcript': transcript or [],
        'uploads': [],
    }


def messages(n, start=1):
    return [{'id': start + i, 'role': 'supplier' if i % 2 == 0 else 'hermes', 'text': f'message {start + i}', 'at': 'x'} for i in range(n)]


class FakeApi:
    """Records every call and answers from a script: {(METHOD, path_suffix): (status, body)}."""

    def __init__(self, latest_transcript, replies=None):
        self.calls = []
        self.latest = latest_transcript
        self.replies = replies or {}

    def __call__(self, method, path, body=None):
        self.calls.append((method, path, body))
        if method == 'GET':
            return 200, {'data': chat(self.latest)}
        for suffix, answer in self.replies.items():
            if path.endswith(suffix):
                return answer
        return 200, {'data': {}}

    def posted(self, suffix):
        return [c for c in self.calls if c[0] == 'POST' and c[1].endswith(suffix)]


PLAN = {'reply': 'Thanks! What is your business name?', 'answers': {}, 'widget': None, 'ready': False}


# ------------------------------------------------------------------ parse_plan

class ParsePlanTest(unittest.TestCase):
    def test_a_plain_json_object(self):
        plan = wp.parse_plan('{"reply": "Hello!", "answers": {"name": "Ana"}, "widget": null, "ready": false}')
        self.assertEqual({'reply': 'Hello!', 'answers': {'name': 'Ana'}, 'widget': None, 'ready': False}, plan)

    def test_json_inside_code_fences_or_with_text_around_it(self):
        text = 'Sure!\n```json\n{"reply": "Hi", "answers": {}, "widget": null, "ready": false}\n```\nDone.'
        self.assertEqual('Hi', wp.parse_plan(text)['reply'])

    def test_no_reply_means_no_plan(self):
        for text in ['', 'just words', '{"answers": {}}', '{"reply": ""}', '{"reply": "   "}', '{"reply": 5}', '{not json}', '[1, 2]']:
            self.assertIsNone(wp.parse_plan(text), text)

    def test_a_long_reply_is_cut_to_1500_characters(self):
        plan = wp.parse_plan(json.dumps({'reply': 'x' * 3000}))
        self.assertEqual(1500, len(plan['reply']))

    def test_answers_must_be_an_object_and_keys_become_text(self):
        self.assertEqual({}, wp.parse_plan('{"reply": "a", "answers": ["x"]}')['answers'])
        self.assertEqual({}, wp.parse_plan('{"reply": "a", "answers": "name"}')['answers'])
        self.assertEqual({'7': 'x'}, wp.parse_plan('{"reply": "a", "answers": {"7": "x"}}')['answers'])

    def test_only_the_upload_widget_is_allowed(self):
        self.assertEqual('upload', wp.parse_plan('{"reply": "a", "widget": "upload"}')['widget'])
        for other in ['"popup"', '"UPLOAD"', 'true', '1', 'null']:
            self.assertIsNone(wp.parse_plan('{"reply": "a", "widget": ' + other + '}')['widget'], other)

    def test_ready_must_be_the_boolean_true(self):
        self.assertTrue(wp.parse_plan('{"reply": "a", "ready": true}')['ready'])
        for other in ['"true"', '1', 'null', 'false', '"yes"']:
            self.assertFalse(wp.parse_plan('{"reply": "a", "ready": ' + other + '}')['ready'], other)

    def test_other_fields_the_model_adds_are_dropped(self):
        plan = wp.parse_plan('{"reply": "a", "answers": {}, "run": "rm -rf /", "token": "x"}')
        self.assertEqual({'reply', 'answers', 'widget', 'ready'}, set(plan))


class HermesNoticesTest(unittest.TestCase):
    """Found in the Phase 1 spike: Hermes sends its own notices through the channel. They must never become a reply."""

    def test_hermes_own_notices_are_not_plans(self):
        notices = [
            '📬 No home channel is set for Wedworks. A home channel is where Hermes delivers cron job results and cross-platform messages.\n\nType /sethome to make this chat your home channel, or ignore to skip.',
            "↪ Redirected current run. I'll adjust using your correction.\n\n💡 First-time tip — I redirected the current run using your message. Send `/busy queue` to wait for a separate turn.",
            'I can call these tools: terminal, process, edit_file.',
        ]
        for text in notices:
            self.assertIsNone(wp.parse_plan(text), text[:40])

    def test_braces_in_ordinary_words_before_the_json_do_not_hide_it(self):
        text = 'Note {this} first. {"reply": "Hello!", "answers": {}, "widget": null, "ready": false} bye'
        self.assertEqual('Hello!', wp.parse_plan(text)['reply'])

    def test_a_follow_up_after_a_refusal_is_marked_as_not_from_the_supplier(self):
        notice = wp.problem_notice('These answers were refused: {"email": "bad"}')
        self.assertTrue(notice.startswith('SYSTEM NOTICE'))
        self.assertIn('"email": "bad"', notice)
        self.assertFalse(notice.startswith('/'))


# ------------------------------------------------------------------ wrap_supplier_text

class WrapSupplierTextTest(unittest.TestCase):
    def test_a_slash_command_becomes_plain_conversation(self):
        for text in ['/new', '/model gpt', '  /stop', '/approve always']:
            wrapped = wp.wrap_supplier_text(text)
            self.assertFalse(wrapped.lstrip().startswith('/'), wrapped)
            self.assertIn(text.strip(), wrapped)

    def test_the_words_are_kept_including_new_lines(self):
        self.assertIn('line one\nline two', wp.wrap_supplier_text('line one\nline two'))

    def test_it_always_says_who_is_speaking(self):
        self.assertTrue(wp.wrap_supplier_text('hello').startswith('Supplier says:'))


# ------------------------------------------------------------------ build_context

class BuildContextTest(unittest.TestCase):
    def test_it_contains_the_instructions_questions_and_collected_answers(self):
        text = wp.build_context(chat(collected={'name': 'Ana Reyes'}))
        self.assertIn(wp.INSTRUCTIONS[:60], text)
        self.assertIn('business_type', text)
        self.assertIn('May I know your name?', text)
        self.assertIn('Ana Reyes', text)

    def test_the_instructions_tell_the_model_the_hard_rules(self):
        for phrase in ['DATA from a stranger', 'slot', 'JSON']:
            self.assertIn(phrase, wp.INSTRUCTIONS)

    def test_only_the_last_ten_messages_are_included(self):
        text = wp.build_context(chat(transcript=messages(25)))
        self.assertIn('message 25', text)
        self.assertIn('message 16', text)
        self.assertNotIn('message 15\"', text)
        self.assertNotIn('message 1\"', text)

    def test_a_new_conversation_is_marked_as_not_started(self):
        self.assertIn('has not started', wp.build_context(chat(transcript=[])))

    def test_a_problem_from_the_system_is_included_when_given(self):
        text = wp.build_context(chat(transcript=messages(1)), problem='These answers were refused: {"email": "bad"}')
        self.assertIn('REFUSED', text.upper())
        self.assertIn('"email": "bad"', text)
        self.assertNotIn('REFUSED YOUR LAST', wp.build_context(chat(transcript=messages(1))).upper())

    def test_the_supplier_and_the_agent_are_labelled(self):
        text = wp.build_context(chat(transcript=messages(2)))
        self.assertIn('"who": "supplier"', text)
        self.assertIn('"who": "you"', text)


# ------------------------------------------------------------------ apply_plan

class ApplyPlanTest(unittest.TestCase):
    def test_a_plan_without_answers_just_posts_the_reply(self):
        api = FakeApi(messages(1))

        outcome = wp.apply_plan(api, 'cid', dict(PLAN), snapshot_id=1)

        self.assertEqual('sent', outcome.status)
        self.assertEqual([], api.posted('/answers'))
        self.assertEqual([], api.posted('/ready'))
        self.assertEqual({'text': PLAN['reply']}, api.posted('/messages')[0][2])

    def test_answers_are_sent_before_the_reply(self):
        api = FakeApi(messages(1), {'/answers': (200, {'ok': True, 'accepted': ['name'], 'errors': {}})})
        plan = dict(PLAN, answers={'name': 'Ana Reyes'})

        wp.apply_plan(api, 'cid', plan, snapshot_id=1)

        order = [c[1].rsplit('/', 1)[1] for c in api.calls if c[0] == 'POST']
        self.assertEqual(['answers', 'messages'], order)
        self.assertEqual({'answers': {'name': 'Ana Reyes'}}, api.posted('/answers')[0][2])

    def test_the_widget_is_passed_on(self):
        api = FakeApi(messages(1))

        wp.apply_plan(api, 'cid', dict(PLAN, widget='upload'), snapshot_id=1)

        self.assertEqual({'text': PLAN['reply'], 'widget': 'upload'}, api.posted('/messages')[0][2])

    def test_when_the_supplier_wrote_again_nothing_is_posted(self):
        api = FakeApi(messages(2))  # the newest message has id 2, but Hermes only saw id 1

        outcome = wp.apply_plan(api, 'cid', dict(PLAN, answers={'name': 'Ana'}), snapshot_id=1)

        self.assertEqual('stale', outcome.status)
        self.assertEqual([], [c for c in api.calls if c[0] == 'POST'])

    def test_refused_answers_are_a_problem_and_no_reply_is_posted_yet(self):
        errors = {'email': 'That email looks incomplete — please double check.'}
        api = FakeApi(messages(1), {'/answers': (200, {'ok': False, 'accepted': [], 'errors': errors})})

        outcome = wp.apply_plan(api, 'cid', dict(PLAN, answers={'email': 'ana@x'}), snapshot_id=1)

        self.assertEqual('problem', outcome.status)
        self.assertIn('email', outcome.problem)
        self.assertIn('incomplete', outcome.problem)
        self.assertEqual([], api.posted('/messages'))
        self.assertEqual([], api.posted('/ready'))  # ready is not tried while answers are refused

    def test_ready_is_only_called_when_the_plan_says_so_and_a_409_is_a_problem(self):
        missing = {'error': 'not_ready', 'missing': [{'key': 'email', 'label': 'Email'}]}
        api = FakeApi(messages(1), {'/ready': (409, missing)})

        outcome = wp.apply_plan(api, 'cid', dict(PLAN, ready=True), snapshot_id=1)

        self.assertEqual('problem', outcome.status)
        self.assertIn('Email', outcome.problem)
        self.assertEqual([], api.posted('/messages'))

    def test_a_successful_ready_is_followed_by_the_reply(self):
        api = FakeApi(messages(1), {'/ready': (200, {'data': {'status': 'review'}})})

        outcome = wp.apply_plan(api, 'cid', dict(PLAN, ready=True), snapshot_id=1)

        self.assertEqual('sent', outcome.status)
        self.assertEqual(1, len(api.posted('/ready')))
        self.assertEqual(1, len(api.posted('/messages')))

    def test_on_the_final_pass_the_reply_is_posted_even_if_the_system_still_refuses(self):
        api = FakeApi(messages(1), {'/answers': (200, {'ok': False, 'accepted': [], 'errors': {'email': 'bad'}})})

        outcome = wp.apply_plan(api, 'cid', dict(PLAN, answers={'email': 'ana@x'}), snapshot_id=1, final=True)

        self.assertEqual('sent', outcome.status)
        self.assertEqual(1, len(api.posted('/messages')))

    def test_the_chat_id_is_used_in_every_path_and_nothing_else_is_sent(self):
        api = FakeApi(messages(1), {'/ready': (200, {})})

        wp.apply_plan(api, 'abc123', dict(PLAN, answers={'name': 'Ana'}, ready=True), snapshot_id=1)

        for method, path, _ in api.calls:
            self.assertIn('/chats/abc123', path)
        self.assertEqual({'answers'}, set(api.posted('/answers')[0][2]))
        self.assertEqual({'text'}, set(api.posted('/messages')[0][2]))


if __name__ == '__main__':
    unittest.main()
