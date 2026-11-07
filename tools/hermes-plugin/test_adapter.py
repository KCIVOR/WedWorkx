"""
Tests for the WedWorks Hermes plugin adapter (tools/hermes-plugin/wedworks/adapter.py).

Hermes' own Python packages are not installed in a normal Python, so these tests replace the few Hermes classes the adapter
uses (BasePlatformAdapter, MessageEvent, Platform, ...) with small stand-ins. They test OUR logic: how a chat becomes a
message event, what is sent back, and what is ignored. How the real Hermes gateway runs the adapter was checked in
Phase 1 (spike) and is checked again end to end in Phase 4.

Run:  python -m unittest discover -s tools/hermes-plugin
"""
import asyncio
import dataclasses
import datetime
import enum
import os
import sys
import types
import unittest
from types import SimpleNamespace
from typing import Any, Optional
from unittest import mock

HERE = os.path.dirname(os.path.abspath(__file__))


# ------------------------------------------------------------------ stand-ins for Hermes

def install_hermes_stand_ins():
    def module(name):
        m = types.ModuleType(name)
        sys.modules[name] = m
        return m

    module('gateway')
    module('gateway.platforms')
    config = module('gateway.config')
    shared = module('gateway.platforms._shared')
    base = module('gateway.platforms.base')
    event = module('gateway.platforms.event')

    class Platform:
        def __init__(self, value):
            self.value = value

    config.Platform = Platform
    shared.extra_or_secret = lambda extra, key, env, default=None: os.environ.get(env) or (extra or {}).get(key, default)
    shared.get_scoped_secret = lambda env, default=None: os.environ.get(env, default)

    @dataclasses.dataclass
    class SendResult:
        success: bool
        message_id: Optional[str] = None
        error: Optional[str] = None

    class BasePlatformAdapter:
        def __init__(self, config, platform):
            self.config = config
            self.platform = platform
            self.events = []
            self.connected = False
            self.fatal = None

        def build_source(self, chat_id, chat_name=None, chat_type='dm', user_id=None, user_name=None, **kw):
            return SimpleNamespace(platform=self.platform, chat_id=chat_id, chat_name=chat_name, chat_type=chat_type, user_id=user_id, user_name=user_name)

        async def handle_message(self, ev):
            self.events.append(ev)

        def _mark_connected(self):
            self.connected = True

        def _mark_disconnected(self):
            self.connected = False

        def _set_fatal_error(self, code, message, retryable):
            self.fatal = (code, message, retryable)

        def _wire_plugin_handlers(self, client):
            pass

    base.SendResult = SendResult
    base.BasePlatformAdapter = BasePlatformAdapter

    class MessageType(enum.Enum):
        TEXT = 'text'

    @dataclasses.dataclass
    class MessageEvent:
        text: str
        message_type: Any = None
        source: Any = None
        message_id: Optional[str] = None
        timestamp: Any = None
        channel_prompt: Optional[str] = None
        allow_gateway_control: bool = True

    event.MessageType = MessageType
    event.MessageEvent = MessageEvent


install_hermes_stand_ins()
sys.path.insert(0, HERE)
import wedworks.adapter as adapter_module  # noqa: E402

CID = 'c' * 40
ENV = {'WEDWORKS_URL': 'http://127.0.0.1:8081', 'WEDWORKS_HERMES_TOKEN': 'test-token'}


def chat(transcript=None, waiting=True, collected=None, cid=CID):
    return {
        'chat_id': cid, 'bot': 'registration', 'waiting_for_reply': waiting, 'supplier': None, 'guidelines': ['Ask one thing at a time.'],
        'questions': [{'key': 'name', 'text': 'May I know your name?', 'required': True, 'fields': ['name'], 'rules': 'Required. Full name.', 'options': None}],
        'collected': collected or {}, 'transcript': transcript or [], 'uploads': [],
    }


def supplier_message(id, text):
    return {'id': id, 'role': 'supplier', 'text': text, 'at': 'x'}


PLAN = '{"reply": "Thanks! What is your email?", "answers": {"name": "Ana Reyes"}, "widget": null, "ready": false}'


class FakeSystem:
    """Answers the adapter's HTTP calls from a script and records them."""

    def __init__(self, chats=None, replies=None):
        self.chats = chats or {}
        self.replies = replies or {}
        self.calls = []

    def __call__(self, method, path, body=None, timeout=15):
        self.calls.append((method, path, body))
        if method == 'GET' and path.startswith('/chats/'):
            cid = path.split('/')[2].split('?')[0]
            return (200, {'data': self.chats[cid]}) if cid in self.chats else (404, {})
        for suffix, answer in self.replies.items():
            if path.endswith(suffix):
                return answer
        return 200, {'data': {}}

    def posted(self, suffix):
        return [c for c in self.calls if c[0] == 'POST' and c[1].endswith(suffix)]


def make_adapter(system=None, env=None):
    with mock.patch.dict(os.environ, env if env is not None else ENV, clear=False):
        a = adapter_module.WedWorksAdapter(SimpleNamespace(extra={}))
    if system is not None:
        a._http = system
    return a


# ------------------------------------------------------------------ from a chat to a message event

class EventTest(unittest.IsolatedAsyncioTestCase):
    async def test_a_supplier_message_becomes_plain_conversation_for_one_chat(self):
        system = FakeSystem({CID: chat([supplier_message(1, '/new')])})
        a = make_adapter(system)

        await a._handle_chat(CID, 1)

        self.assertEqual(1, len(a.events))
        ev = a.events[0]
        self.assertTrue(ev.text.startswith('Supplier says:'))
        self.assertFalse(ev.text.lstrip().startswith('/'))
        self.assertFalse(ev.allow_gateway_control)
        self.assertIn('May I know your name?', ev.channel_prompt)
        self.assertIn(adapter_module.wp.INSTRUCTIONS[:50], ev.channel_prompt)
        self.assertEqual(('dm', CID, CID), (ev.source.chat_type, ev.source.chat_id, ev.source.user_id))

    async def test_a_new_chat_asks_the_agent_to_greet(self):
        a = make_adapter(FakeSystem({CID: chat([])}))

        await a._handle_chat(CID, 0)

        self.assertIn('Greet them', a.events[0].text)

    async def test_the_same_supplier_message_is_handed_over_only_once(self):
        a = make_adapter(FakeSystem({CID: chat([supplier_message(1, 'hi')])}))

        await a._handle_chat(CID, 1)
        await a._handle_chat(CID, 1)

        self.assertEqual(1, len(a.events))

    async def test_a_newer_supplier_message_is_handed_over_again(self):
        system = FakeSystem({CID: chat([supplier_message(1, 'hi')])})
        a = make_adapter(system)
        await a._handle_chat(CID, 1)

        system.chats[CID] = chat([supplier_message(1, 'hi'), supplier_message(2, 'wait, one more thing')])
        await a._handle_chat(CID, 2)

        self.assertEqual(2, len(a.events))
        self.assertIn('one more thing', a.events[1].text)

    async def test_a_chat_that_is_no_longer_waiting_gets_no_event(self):
        a = make_adapter(FakeSystem({CID: chat([supplier_message(1, 'hi')], waiting=False)}))

        await a._handle_chat(CID, 1)

        self.assertEqual([], a.events)

    async def test_an_unknown_chat_gets_no_event_and_no_error(self):
        a = make_adapter(FakeSystem({}))

        await a._handle_chat(CID, 1)

        self.assertEqual([], a.events)

    async def test_the_chat_is_marked_seen_before_it_is_handed_over(self):
        a = make_adapter(FakeSystem({CID: chat([supplier_message(1, 'hi')])}))
        seen_during = {}

        async def spy(event):
            seen_during.update(a._seen)

        a.handle_message = spy
        await a._handle_chat(CID, 7)

        self.assertEqual({CID: 7}, seen_during)  # so the next held request does not return at once for a chat in progress


class FailureTest(unittest.IsolatedAsyncioTestCase):
    """Found in Phase 4: a failed request to the website must not leave a chat without an answer."""

    async def test_a_failed_detail_request_puts_the_chat_back_so_it_is_asked_again(self):
        for failure in (lambda: (500, {}), lambda: (_ for _ in ()).throw(OSError('refused'))):
            a = make_adapter()
            a._http = lambda *args, _f=failure, **kw: _f()
            a._sleep = lambda seconds: asyncio.sleep(0)

            await a._handle_chat(CID, 3)

            self.assertEqual([], a.events)
            self.assertNotIn(CID, a._seen)  # the next held request returns the chat again
            self.assertNotIn(CID, a._dispatched)

    async def test_an_unknown_chat_is_left_alone(self):
        a = make_adapter(FakeSystem({}))

        await a._handle_chat(CID, 3)

        self.assertIn(CID, a._seen)  # a 404 is final: not retried

    async def test_a_reply_that_fails_once_is_sent_on_the_second_try(self):
        system = FakeSystem({CID: chat([supplier_message(1, 'hi')])}, {'/answers': (200, {'ok': True, 'errors': {}})})
        a = make_adapter(system)
        await a._handle_chat(CID, 1)
        real = adapter_module.wp.apply_plan
        calls = []

        def flaky(*args, **kwargs):
            calls.append(1)
            if len(calls) == 1:
                raise OSError('busy')
            return real(*args, **kwargs)

        a._sleep = lambda seconds: asyncio.sleep(0)
        with mock.patch.object(adapter_module.wp, 'apply_plan', flaky):
            result = await a.send(CID, PLAN)

        self.assertTrue(result.success)
        self.assertEqual(2, len(calls))
        self.assertEqual(1, len(system.posted('/messages')))

    async def test_a_reply_that_keeps_failing_is_dropped_without_crashing(self):
        a = make_adapter(FakeSystem({CID: chat([supplier_message(1, 'hi')])}))
        await a._handle_chat(CID, 1)
        a._sleep = lambda seconds: asyncio.sleep(0)

        def always(*args, **kwargs):
            raise OSError('down')

        with mock.patch.object(adapter_module.wp, 'apply_plan', always):
            result = await a.send(CID, PLAN)

        self.assertTrue(result.success)  # the website's own fallback covers the missing reply


# ------------------------------------------------------------------ what the agent sends back

class SendTest(unittest.IsolatedAsyncioTestCase):
    async def asyncSetUp(self):
        self.system = FakeSystem({CID: chat([supplier_message(1, 'hi')])}, {'/answers': (200, {'ok': True, 'accepted': ['name'], 'errors': {}})})
        self.a = make_adapter(self.system)
        await self.a._handle_chat(CID, 1)  # the supplier message is now with Hermes (snapshot id 1)
        self.system.calls.clear()

    async def test_hermes_own_notices_are_ignored(self):
        for text in ['📬 No home channel is set for Wedworks.', "↪ Redirected current run. I'll adjust.", 'plain words, no json']:
            result = await self.a.send(CID, text)
            self.assertTrue(result.success)
        self.assertEqual([], [c for c in self.system.calls if c[0] == 'POST'])

    async def test_interim_sends_are_ignored(self):
        await self.a.send(CID, PLAN, metadata={'_interim_send': True})

        self.assertEqual([], self.system.calls)

    async def test_a_plan_sends_the_answers_and_then_the_reply(self):
        await self.a.send(CID, PLAN)

        order = [c[1].rsplit('/', 1)[1] for c in self.system.calls if c[0] == 'POST']
        self.assertEqual(['answers', 'messages'], order)
        self.assertEqual({'text': 'Thanks! What is your email?'}, self.system.posted('/messages')[0][2])
        self.assertNotIn(CID, self.a._seen)  # answered: the chat may be handed over again for its next message

    async def test_a_plan_inside_code_fences_is_accepted(self):
        await self.a.send(CID, '```json\n' + PLAN + '\n```')

        self.assertEqual(1, len(self.system.posted('/messages')))

    async def test_a_reply_is_dropped_when_the_supplier_wrote_again(self):
        self.system.chats[CID] = chat([supplier_message(1, 'hi'), supplier_message(2, 'actually, one more thing')])

        await self.a.send(CID, PLAN)

        self.assertEqual([], [c for c in self.system.calls if c[0] == 'POST'])

    async def test_a_refusal_sends_one_follow_up_turn_that_is_not_from_the_supplier(self):
        self.system.replies['/answers'] = (200, {'ok': False, 'accepted': [], 'errors': {'email': 'That email looks incomplete'}})
        plan = '{"reply": "Got it!", "answers": {"email": "ana@x"}, "widget": null, "ready": false}'

        await self.a.send(CID, plan)

        self.assertEqual([], self.system.posted('/messages'))  # nothing shown to the supplier yet
        self.assertEqual(1, len(self.a.events) - 1)  # the first event came from setUp's _handle_chat: one more is the follow-up
        follow_up = self.a.events[-1]
        self.assertTrue(follow_up.text.startswith('SYSTEM NOTICE'))
        self.assertIn('incomplete', follow_up.text)
        self.assertIn('Required. Full name.', follow_up.channel_prompt)  # the rules still travel with the follow-up
        self.assertFalse(follow_up.allow_gateway_control)
        self.assertIn(CID, self.a._final)

    async def test_after_a_follow_up_the_reply_is_posted_even_if_the_system_still_refuses(self):
        self.system.replies['/answers'] = (200, {'ok': False, 'accepted': [], 'errors': {'email': 'bad'}})
        plan = '{"reply": "Got it!", "answers": {"email": "ana@x"}, "widget": null, "ready": false}'
        await self.a.send(CID, plan)  # refused: follow-up
        self.system.calls.clear()

        await self.a.send(CID, plan)  # the agent's second try

        self.assertEqual(1, len(self.system.posted('/messages')))
        self.assertNotIn(CID, self.a._final)
        self.assertEqual(2, len(self.a.events))  # no second follow-up

    async def test_ready_too_early_is_also_one_follow_up(self):
        self.system.replies['/ready'] = (409, {'error': 'not_ready', 'missing': [{'key': 'email', 'label': 'Email'}]})

        await self.a.send(CID, '{"reply": "All done!", "answers": {}, "widget": null, "ready": true}')

        self.assertIn('Email', self.a.events[-1].text)
        self.assertEqual([], self.system.posted('/messages'))

    async def test_the_upload_widget_is_passed_on(self):
        await self.a.send(CID, '{"reply": "Please add photos.", "answers": {}, "widget": "upload", "ready": false}')

        self.assertEqual({'text': 'Please add photos.', 'widget': 'upload'}, self.system.posted('/messages')[0][2])

    async def test_a_reply_for_a_chat_not_handed_over_here_still_works(self):
        a = make_adapter(self.system)  # a fresh adapter that never saw the chat (for example after a restart)

        await a.send(CID, PLAN)

        self.assertEqual(1, len(self.system.posted('/messages')))


# ------------------------------------------------------------------ the held request

class Stop(BaseException):
    """Ends a scripted poll loop. A BaseException, so the adapter's "keep listening" handler does not swallow it."""


class PollTest(unittest.IsolatedAsyncioTestCase):
    def loop_with(self, a, responses):
        """Runs the poll loop until the scripted responses are used up; returns the sleeps it asked for."""
        sleeps = []
        calls = []
        script = iter(responses)

        def http(method, path, body=None, timeout=15):
            calls.append((method, path, timeout))
            try:
                answer = next(script)
            except StopIteration:
                raise Stop()
            if isinstance(answer, Exception):
                raise answer
            return answer

        async def sleep(seconds):
            sleeps.append(seconds)

        a._http = http
        a._sleep = sleep
        return calls, sleeps

    async def run_loop(self, a):
        try:
            await a._poll_loop()
        except Stop:
            pass

    async def test_it_asks_for_a_held_request_as_the_plugin_consumer(self):
        a = make_adapter()
        calls, _ = self.loop_with(a, [(200, {'data': []})])

        await self.run_loop(a)

        self.assertEqual('GET', calls[0][0])
        self.assertEqual('/chats?wait=20&consumer=wedworks-plugin', calls[0][1])
        self.assertGreater(calls[0][2], 20)  # the HTTP timeout is longer than the hold

    async def test_chats_in_progress_are_listed_as_seen(self):
        a = make_adapter()
        a._seen = {CID: 3}
        calls, _ = self.loop_with(a, [(200, {'data': []})])

        await self.run_loop(a)

        self.assertIn(f'&seen={CID}:3', calls[0][1])

    async def test_waiting_chats_are_handed_to_the_turn_handler(self):
        a = make_adapter()
        handled = []

        async def handle(chat_id, count):
            handled.append((chat_id, count))

        a._handle_chat = handle
        self.loop_with(a, [(200, {'data': [{'chat_id': CID, 'bot': 'registration', 'messages': 2}]})])

        await self.run_loop(a)
        await asyncio.sleep(0)

        self.assertEqual([(CID, 2)], handled)

    async def test_when_another_consumer_holds_the_lease_it_stays_quiet(self):
        a = make_adapter()
        handled = []
        a._handle_chat = lambda *args: handled.append(args)
        _, sleeps = self.loop_with(a, [(200, {'data': [], 'held_by': 'bridge'}), (200, {'data': [], 'held_by': 'bridge'})])

        await self.run_loop(a)

        self.assertEqual([], handled)
        self.assertEqual([2, 2], sleeps)

    async def test_a_wrong_token_waits_thirty_seconds_and_keeps_trying(self):
        a = make_adapter()
        _, sleeps = self.loop_with(a, [(401, {})])

        await self.run_loop(a)

        self.assertEqual([30], sleeps)

    async def test_a_changed_token_is_picked_up_after_a_401_without_waiting(self):
        a = make_adapter()
        _, sleeps = self.loop_with(a, [(401, {}), (200, {'data': []})])

        with mock.patch.dict(os.environ, {'WEDWORKS_HERMES_TOKEN': 'a-new-token'}):
            await self.run_loop(a)

        self.assertEqual([], sleeps)  # no 30-second wait: the new token is tried at once
        self.assertEqual('a-new-token', a.token)

    async def test_an_unreachable_system_is_retried_with_growing_waits(self):
        a = make_adapter()
        _, sleeps = self.loop_with(a, [OSError('refused'), OSError('refused'), (500, {}), OSError('x'), OSError('x'), OSError('x')])

        await self.run_loop(a)

        self.assertEqual([2, 5, 10, 30, 30, 30], sleeps)

    async def test_it_recovers_after_an_outage(self):
        a = make_adapter()
        _, sleeps = self.loop_with(a, [OSError('refused'), (200, {'data': []}), (200, {'data': []})])

        await self.run_loop(a)

        self.assertEqual([2], sleeps)  # one wait after the failure, none after the recovery

    async def test_the_hold_time_is_kept_between_one_and_twenty_seconds(self):
        for configured, expected in [('0', 1), ('99', 20), ('x', 20), ('7', 7), ('', 20)]:
            a = make_adapter(env={**ENV, 'WEDWORKS_WAIT': configured})
            self.assertEqual(expected, a.wait, configured)


# ------------------------------------------------------------------ connecting and registering

class ConnectTest(unittest.IsolatedAsyncioTestCase):
    async def test_without_settings_it_does_not_connect(self):
        a = make_adapter(env={'WEDWORKS_URL': '', 'WEDWORKS_HERMES_TOKEN': ''})

        self.assertFalse(await a.connect())
        self.assertEqual('config_missing', a.fatal[0])
        self.assertFalse(a.connected)

    async def test_with_settings_it_connects_and_disconnect_stops_the_loop(self):
        a = make_adapter(FakeSystem())
        a._http = lambda *args, **kwargs: (200, {'data': []})

        async def quick_sleep(seconds):
            await asyncio.sleep(0.01)

        a._sleep = quick_sleep

        self.assertTrue(await a.connect())
        self.assertTrue(a.connected)
        await a.disconnect()
        self.assertFalse(a.connected)
        self.assertIsNone(a._task)

    async def test_the_token_is_never_copied_into_the_config(self):
        with mock.patch.dict(os.environ, ENV):
            self.assertEqual({'url': 'http://127.0.0.1:8081'}, adapter_module._env_enablement())


class RegisterTest(unittest.TestCase):
    def test_the_platform_is_registered_safely(self):
        calls = []
        ctx = SimpleNamespace(register_platform=lambda **kwargs: calls.append(kwargs))

        adapter_module.register(ctx)

        k = calls[0]
        self.assertEqual('wedworks', k['name'])
        self.assertIs(adapter_module.WedWorksAdapter, k['adapter_factory'])
        self.assertEqual('WEDWORKS_ALLOW_ALL_USERS', k['allow_all_env'])
        self.assertEqual(0, k['max_message_length'])  # the JSON reply must never be split into parts
        self.assertEqual('minimal', k['display_tier'])
        self.assertIn('JSON', k['platform_hint'])
        self.assertEqual(['WEDWORKS_URL', 'WEDWORKS_HERMES_TOKEN'], k['required_env'])
        self.assertNotIn('trusted_inbound', k)  # strangers write here: never trusted by default

    def test_check_and_validate_need_both_settings(self):
        with mock.patch.dict(os.environ, {'WEDWORKS_URL': '', 'WEDWORKS_HERMES_TOKEN': ''}):
            self.assertFalse(adapter_module.check_requirements())
            self.assertFalse(adapter_module.validate_config(SimpleNamespace(extra={})))
            self.assertIsNone(adapter_module._env_enablement())
        with mock.patch.dict(os.environ, ENV):
            self.assertTrue(adapter_module.check_requirements())
            self.assertTrue(adapter_module.validate_config(SimpleNamespace(extra={})))


if __name__ == '__main__':
    unittest.main()
