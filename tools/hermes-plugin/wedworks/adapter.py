"""WedWorks platform adapter for Hermes Agent: supplier chats from the WedWorks website, answered by this Hermes profile.

How it works
    * The adapter keeps ONE request open to the WedWorks system (`GET /api/hermes/chats?wait=20`). When a supplier writes,
      the system answers it at once; otherwise it returns after 20 seconds and the adapter asks again. No fast polling.
    * Each supplier turn becomes one Hermes message event. The supplier's words are wrapped as plain conversation, and the
      rules, questions and answers so far travel in the event's `channel_prompt`.
    * The agent answers with one JSON object. `send()` reads it and sends the answers, "ready" and the reply back to the
      system through the shared protocol (wedworks_protocol.py). The system checks every answer again and the supplier
      confirms at the end, so this adapter never creates or confirms anything itself.
    * Anything else that arrives through `send()` (Hermes' own notices such as "No home channel is set") is ignored.

Settings (the profile's .env, or `platforms.wedworks.extra` in config.yaml):
    WEDWORKS_URL               the website, for example http://127.0.0.1:8081 (the adapter adds /api/hermes)
    WEDWORKS_HERMES_TOKEN      the Hermes token from Admin > Settings (never written to config or logs)
    WEDWORKS_WAIT              seconds one request is held open, 1 to 20 (default 20)
    WEDWORKS_ALLOW_ALL_USERS   true: every chat id may talk to the agent (the adapter opens no port and only handles
                               chats it fetched from the system with the token)

The profile must have no tools for this platform: `platform_toolsets.wedworks: []` (install.ps1 sets it).
"""
import asyncio
import datetime
import json
import logging
import os
import sys
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any, Optional

from gateway.config import Platform
from gateway.platforms._shared import extra_or_secret as _extra_or_secret
from gateway.platforms._shared import get_scoped_secret as _get_scoped_secret
from gateway.platforms.base import BasePlatformAdapter, SendResult
from gateway.platforms.event import MessageEvent, MessageType

try:  # installed next to this file by install.ps1
    from . import wedworks_protocol as wp
except ImportError:  # running from the project folder (tools/hermes-plugin/wedworks)
    sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', 'hermes-shared'))
    import wedworks_protocol as wp

logger = logging.getLogger(__name__)

CONSUMER = 'wedworks-plugin'
RETRY_DELAYS = (2, 5, 10, 30)  # seconds to wait after the system could not be reached, growing
MAX_REMEMBERED = 500  # chats kept in memory


def _clamp_wait(value: Any) -> int:
    try:
        return max(1, min(20, int(value)))
    except (TypeError, ValueError):
        return 20


class WedWorksAdapter(BasePlatformAdapter):
    def __init__(self, config, **kwargs):
        super().__init__(config=config, platform=Platform('wedworks'))
        extra = getattr(config, 'extra', {}) or {}
        self.url = (_extra_or_secret(extra, 'url', 'WEDWORKS_URL') or '').rstrip('/')
        self.token = _extra_or_secret(extra, 'token', 'WEDWORKS_HERMES_TOKEN') or ''
        self.wait = _clamp_wait(_extra_or_secret(extra, 'wait', 'WEDWORKS_WAIT') or 20)
        self._task: Optional[asyncio.Task] = None
        self._dispatched: dict[str, int] = {}  # chat id -> newest supplier message id handed to Hermes
        self._seen: dict[str, int] = {}  # chat id -> message count when handed over (the "seen" parameter)
        self._final: set[str] = set()  # chats whose next reply is the last try after the system refused something
        self._held_logged = False
        self._sleep = asyncio.sleep  # replaced in tests

    @property
    def name(self) -> str:
        return 'WedWorks'

    # ------------------------------------------------------------------ connection

    async def connect(self, *, is_reconnect: bool = False) -> bool:
        if not self.url or not self.token:
            self._set_fatal_error('config_missing', 'WEDWORKS_URL and WEDWORKS_HERMES_TOKEN must be set', retryable=False)
            return False
        self._task = asyncio.create_task(self._poll_loop())
        self._mark_connected()
        self._wire_plugin_handlers(None)
        logger.info('WedWorks: listening for supplier chats at %s (one held request of up to %ss)', urllib.parse.urlparse(self.url).netloc, self.wait)
        return True

    async def disconnect(self) -> None:
        self._mark_disconnected()
        if self._task:
            self._task.cancel()
            try:
                await self._task
            except (asyncio.CancelledError, Exception):  # noqa: BLE001
                pass
            self._task = None

    async def _poll_loop(self) -> None:
        failures = 0
        while True:
            try:
                path = f'/chats?wait={self.wait}&consumer={CONSUMER}'
                seen = ','.join(f'{chat}:{count}' for chat, count in list(self._seen.items())[-40:])
                if seen:
                    path += '&seen=' + urllib.parse.quote(seen, safe=':,')

                status, body = await asyncio.to_thread(self._http, 'GET', path, None, self.wait + 15)
                if status == 401:
                    if self._reload_settings():
                        logger.info('WedWorks: the settings changed; trying again with the new ones')
                        continue
                    logger.error('WedWorks: the system refused the token (401). Create a new one in Admin > Settings and update the profile .env')
                    await self._sleep(30)
                    continue
                if status != 200:
                    raise RuntimeError(f'HTTP {status}')
                if failures:
                    logger.info('WedWorks: connected again')
                    failures = 0

                if body.get('held_by'):  # the bridge (or another consumer) is answering: stay quiet
                    if not self._held_logged:
                        logger.info('WedWorks: "%s" is answering the chats; waiting', body['held_by'])
                        self._held_logged = True
                    await self._sleep(2)
                    continue
                self._held_logged = False

                rows = body.get('data', [])
                for row in rows:
                    asyncio.create_task(self._handle_chat(row['chat_id'], int(row.get('messages', 0))))
                if rows:
                    await self._sleep(0.2)  # let the turns start before asking again
            except asyncio.CancelledError:
                raise
            except Exception as e:  # noqa: BLE001 - keep listening whatever happens
                failures += 1
                if failures == 1:
                    logger.warning('WedWorks: cannot reach the system (%s). Will keep trying.', type(e).__name__)
                await self._sleep(RETRY_DELAYS[min(failures - 1, len(RETRY_DELAYS) - 1)])

    def _reload_settings(self) -> bool:
        """Re-reads the address and token (the profile .env may have changed since this adapter started). True if they differ."""
        extra = getattr(self.config, 'extra', {}) or {}
        url = (_extra_or_secret(extra, 'url', 'WEDWORKS_URL') or '').rstrip('/')
        token = _extra_or_secret(extra, 'token', 'WEDWORKS_HERMES_TOKEN') or ''
        if token and (token != self.token or (url and url != self.url)):
            self.token, self.url = token, (url or self.url)
            return True
        return False

    # ------------------------------------------------------------------ one supplier turn

    async def _handle_chat(self, chat_id: str, count: int) -> None:
        self._seen[chat_id] = count  # taken: do not hand this chat back until a new message arrives
        self._prune()
        try:
            status, body = await asyncio.to_thread(self._http, 'GET', f'/chats/{chat_id}')
            if status == 404:
                return  # ended or unknown: nothing to do
            if status != 200:
                raise RuntimeError(f'HTTP {status}')
            chat = body['data']
            if not chat.get('waiting_for_reply'):
                return
            latest = wp.last_message_id(chat)
            if self._dispatched.get(chat_id) == latest:
                return  # this supplier message is already with Hermes
            self._dispatched[chat_id] = latest
        except asyncio.CancelledError:
            raise
        except Exception as e:  # noqa: BLE001 - a failed request must not orphan the chat
            self._seen.pop(chat_id, None)  # so the next held request returns it again
            logger.warning('WedWorks: could not read chat %s (%s); it will be tried again', chat_id[:6], type(e).__name__)
            await self._sleep(1)
            return
        await self.handle_message(self._event(chat))

    def _event(self, chat: dict, text: Optional[str] = None) -> MessageEvent:
        """One message event for Hermes. The supplier's words are plain conversation, never a command."""
        chat_id = chat['chat_id']
        if text is None:
            supplier = next((m for m in reversed(chat['transcript']) if m['role'] == 'supplier'), None)
            text = wp.wrap_supplier_text(supplier['text']) if supplier else '(A supplier just opened the chat. Greet them and ask the first question.)'

        source = self.build_source(chat_id=chat_id, chat_name=chat_id, chat_type='dm', user_id=chat_id, user_name='Supplier')

        return MessageEvent(
            text=text,
            message_type=MessageType.TEXT,
            source=source,
            message_id=uuid.uuid4().hex,
            timestamp=datetime.datetime.now(),
            channel_prompt=wp.build_context(chat),
            allow_gateway_control=False,  # a "/new" or "/model" from a supplier is just words
        )

    # ------------------------------------------------------------------ the agent's answer

    async def send(self, chat_id, content, reply_to=None, metadata=None):
        ok = SendResult(success=True, message_id=uuid.uuid4().hex)
        if (metadata or {}).get('_interim_send'):
            return ok

        plan = wp.parse_plan(content or '')
        if not plan:
            logger.info('WedWorks: ignored a message that is not a reply plan (%d characters) for chat %s', len(content or ''), chat_id[:6])
            return ok  # Hermes' own notices, or a model slip: the system's fallback covers a missing reply

        snapshot = self._dispatched.get(chat_id)
        if snapshot is None:
            status, body = await asyncio.to_thread(self._http, 'GET', f'/chats/{chat_id}')
            snapshot = wp.last_message_id(body['data']) if status == 200 else 0

        final = chat_id in self._final
        self._final.discard(chat_id)
        outcome = None
        for attempt in range(2):  # the system may be busy for a moment: one more try before the reply is lost
            try:
                outcome = await asyncio.to_thread(wp.apply_plan, self._http, chat_id, plan, snapshot, final)
                break
            except asyncio.CancelledError:
                raise
            except Exception as e:  # noqa: BLE001
                logger.warning('WedWorks: could not send the reply for chat %s (%s)%s', chat_id[:6], type(e).__name__, '; trying once more' if attempt == 0 else '')
                if attempt == 0:
                    await self._sleep(1)
        if outcome is None:
            return ok  # the system's own fallback covers a missing reply

        if outcome.status == 'problem':
            # The system refused something: tell the agent once, in a follow-up turn that is not from the supplier.
            self._final.add(chat_id)
            status, body = await asyncio.to_thread(self._http, 'GET', f'/chats/{chat_id}')
            if status == 200:
                chat = body['data']
                await self.handle_message(self._event(chat, wp.problem_notice(outcome.problem)))
        elif outcome.status == 'sent':
            self._seen.pop(chat_id, None)
            logger.info('WedWorks: replied to chat %s%s', chat_id[:6], ' [ready]' if plan['ready'] else '')
        else:
            logger.info('WedWorks: dropped a stale reply for chat %s (the supplier wrote again)', chat_id[:6])

        return ok

    async def send_typing(self, chat_id, metadata=None) -> None:
        return None  # the website shows its own typing dots while it waits

    async def get_chat_info(self, chat_id) -> dict[str, Any]:
        return {'name': chat_id, 'type': 'dm'}

    # ------------------------------------------------------------------ HTTP (blocking: always called in a thread)

    def _http(self, method: str, path: str, body: Optional[dict] = None, timeout: int = 15):
        data = json.dumps(body).encode() if body is not None else None
        request = urllib.request.Request(self.url + '/api/hermes' + path, data=data, method=method, headers={
            'Authorization': 'Bearer ' + self.token, 'Accept': 'application/json', 'Content-Type': 'application/json',
        })
        try:
            with urllib.request.urlopen(request, timeout=timeout) as response:
                return response.status, json.loads(response.read().decode() or '{}')
        except urllib.error.HTTPError as e:
            try:
                return e.code, json.loads(e.read().decode() or '{}')
            except ValueError:
                return e.code, {}

    def _prune(self) -> None:
        for table in (self._dispatched, self._seen):
            while len(table) > MAX_REMEMBERED:
                table.pop(next(iter(table)))


# ------------------------------------------------------------------ plugin registration

def _settings(config=None):
    extra = getattr(config, 'extra', None) or {}
    return _extra_or_secret(extra, 'url', 'WEDWORKS_URL'), _extra_or_secret(extra, 'token', 'WEDWORKS_HERMES_TOKEN')


def check_requirements() -> bool:
    return bool(_get_scoped_secret('WEDWORKS_URL', '') and _get_scoped_secret('WEDWORKS_HERMES_TOKEN', ''))


def validate_config(config) -> bool:
    url, token = _settings(config)
    return bool(url and token)


def is_connected(config) -> bool:
    return validate_config(config)


def _env_enablement() -> Optional[dict]:
    url = _get_scoped_secret('WEDWORKS_URL', '').strip()
    if not (url and _get_scoped_secret('WEDWORKS_HERMES_TOKEN', '').strip()):
        return None
    return {'url': url}  # the token stays in the environment: it is never copied into config


def register(ctx):
    """Plugin entry point: called by the Hermes plugin system."""
    ctx.register_platform(
        name='wedworks',
        label='WedWorks',
        adapter_factory=WedWorksAdapter,
        check_fn=check_requirements,
        validate_config=validate_config,
        is_connected=is_connected,
        required_env=['WEDWORKS_URL', 'WEDWORKS_HERMES_TOKEN'],
        install_hint='No extra packages needed (stdlib only)',
        env_enablement_fn=_env_enablement,
        allow_all_env='WEDWORKS_ALLOW_ALL_USERS',
        max_message_length=0,  # the agent's JSON reply must arrive in one piece, never split into parts
        emoji='💍',
        display_tier='minimal',
        platform_hint=wp.PLATFORM_HINT,
    )
