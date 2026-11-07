#!/usr/bin/env python3
"""
WedWorks <-> Hermes bridge (the fallback to the Hermes plugin).

Runs on your computer, next to Hermes. It checks the WedWorks system for supplier chats that are waiting for a
reply (every second or two), asks ONE Hermes profile to write the reply, and sends the reply and the collected
answers back to the system.

If the Hermes plugin (tools/hermes-plugin) is running, it holds the lease and this bridge waits quietly: only one of
them answers chats at a time. If the plugin stops, the bridge takes over after about 30 seconds.

Safety:
  * Hermes is started with every tool switched off (only a harmless to-do toolset), so nothing a supplier types can
    make it run commands, read files or use your other connections.
  * Hermes never sees the token. It only returns text; this program decides what is sent.
  * The system checks every answer again with its own rules, and the supplier confirms at the end.

Needs only Python 3 (no packages). Settings come from environment variables or a file named .env next to this script.
The chat protocol (instructions, reading the reply, applying it) is the shared file tools/hermes-shared/wedworks_protocol.py.
"""
import json
import os
import shutil
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.request
from concurrent.futures import ThreadPoolExecutor

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, os.path.join(HERE, '..', 'hermes-shared'))
try:
    import wedworks_protocol as wp
except ImportError:
    print('Missing tools\\hermes-shared\\wedworks_protocol.py. Keep the hermes-bridge and hermes-shared folders together.')
    raise SystemExit(1)


def load_env():
    path = os.path.join(HERE, '.env')
    if not os.path.exists(path):
        return
    with open(path, encoding='utf-8') as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#') or '=' not in line:
                continue
            key, _, value = line.partition('=')
            os.environ.setdefault(key.strip(), value.strip().strip('"').strip("'"))


load_env()

URL = os.environ.get('WEDWORKS_URL', 'http://localhost:8000').rstrip('/') + '/api/hermes'
TOKEN = os.environ.get('WEDWORKS_HERMES_TOKEN', '')
PROFILE = os.environ.get('HERMES_PROFILE', '')
TOOLSET = os.environ.get('HERMES_TOOLSET', 'todo')  # a harmless toolset; the chat needs no tools
POLL = float(os.environ.get('POLL_SECONDS', '1.5'))
PARALLEL = int(os.environ.get('MAX_PARALLEL', '3'))
CALL_TIMEOUT = int(os.environ.get('CALL_TIMEOUT', '40'))
SHOW = os.environ.get('SHOW_MESSAGES', '0') == '1'  # print what suppliers and Hermes say (off by default: privacy)

if hasattr(sys.stdout, 'reconfigure'):
    sys.stdout.reconfigure(encoding='utf-8', errors='replace')


def log(msg):
    print(time.strftime('%H:%M:%S'), msg, flush=True)


def find_hermes():
    exe = os.environ.get('HERMES_EXE') or shutil.which('hermes')
    if not exe:
        guess = os.path.join(os.environ.get('LOCALAPPDATA', ''), 'hermes', 'bin', 'hermes.exe')
        exe = guess if os.path.exists(guess) else None
    return exe


# ------------------------------------------------------------------ the WedWorks API

def api(method, path, body=None):
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(URL + path, data=data, method=method, headers={
        'Authorization': 'Bearer ' + TOKEN, 'Accept': 'application/json', 'Content-Type': 'application/json',
    })
    try:
        with urllib.request.urlopen(req, timeout=15) as r:
            return r.status, json.loads(r.read().decode() or '{}')
    except urllib.error.HTTPError as e:
        try:
            return e.code, json.loads(e.read().decode() or '{}')
        except ValueError:
            return e.code, {}


# ------------------------------------------------------------------ talking to Hermes

def ask_hermes(exe, prompt):
    cmd = [exe]
    if PROFILE:
        cmd += ['-p', PROFILE]
    cmd += ['-z', prompt, '-t', TOOLSET, '--ignore-rules']
    started = time.time()
    r = subprocess.run(cmd, capture_output=True, timeout=CALL_TIMEOUT)
    out = r.stdout.decode('utf-8', 'replace').strip()
    if r.returncode != 0 or not out:
        raise RuntimeError('Hermes returned an error: ' + r.stderr.decode('utf-8', 'replace').strip()[:200])
    return out, time.time() - started


# ------------------------------------------------------------------ one chat

def handle(exe, chat_id):
    status, body = api('GET', f'/chats/{chat_id}')
    if status != 200:
        return
    chat = body['data']
    if not chat['waiting_for_reply']:
        return
    snapshot = wp.last_message_id(chat)

    problem = None
    for attempt in range(2):
        text, seconds = ask_hermes(exe, wp.build_context(chat, problem) + '\n\nNow reply with the JSON object only.')
        plan = wp.parse_plan(text)
        if not plan:
            log(f'[{chat_id[:6]}] Hermes did not return usable JSON; leaving the chat to the system fallback')
            return

        outcome = wp.apply_plan(api, chat_id, plan, snapshot, final=(attempt == 1))
        if outcome.status == 'stale':
            log(f'[{chat_id[:6]}] the supplier wrote again; starting over')
            return
        if outcome.status == 'problem':
            log(f'[{chat_id[:6]}] the system refused something; asking Hermes again')
            problem = outcome.problem
            status, body = api('GET', f'/chats/{chat_id}')
            if status != 200:
                return
            chat = body['data']
            continue

        log(f'[{chat_id[:6]}] replied in {seconds:.1f}s' + (f' (HTTP {outcome.http})' if outcome.http != 200 else '') + (' [ready]' if plan['ready'] else ''))
        if SHOW:
            log('    supplier: ' + next((m['text'] for m in reversed(chat['transcript']) if m['role'] == 'supplier'), '(new chat)'))
            log('    hermes:   ' + plan['reply'])
        return


# ------------------------------------------------------------------ the loop

def main():
    if not TOKEN:
        print('No token. Copy .env.example to .env in this folder and paste the Hermes token into WEDWORKS_HERMES_TOKEN.')
        return 1
    exe = find_hermes()
    if not exe:
        print('Could not find Hermes. Set HERMES_EXE in the .env file to the full path of hermes.exe.')
        return 1

    log(f'WedWorks bridge started. System: {URL}. Hermes profile: {PROFILE or "(default)"}. Press Ctrl+C to stop.')
    inflight = set()
    lock = threading.Lock()
    pool = ThreadPoolExecutor(max_workers=PARALLEL)

    def work(chat_id):
        try:
            handle(exe, chat_id)
        except subprocess.TimeoutExpired:
            log(f'[{chat_id[:6]}] Hermes took longer than {CALL_TIMEOUT}s; leaving the chat to the system fallback')
        except Exception as e:  # keep running whatever happens to one chat
            log(f'[{chat_id[:6]}] error: {e}')
        finally:
            with lock:
                inflight.discard(chat_id)

    failing = False
    held_by = None
    try:
        while True:
            try:
                status, body = api('GET', '/chats?consumer=bridge')
            except Exception as e:
                if not failing:
                    log(f'Cannot reach the system ({e}). Will keep trying.')
                failing = True
                time.sleep(3)
                continue
            if status == 401:
                print('The system refused the token (401). Create a new one in Admin > Settings and update .env.')
                return 1
            if failing and status == 200:
                log('Connected again.')
            failing = status != 200

            if status == 200:
                # Another consumer (the Hermes plugin) holds the lease: stay quiet until it stops.
                if body.get('held_by') != held_by:
                    held_by = body.get('held_by')
                    log(f'Waiting: "{held_by}" is answering the chats.' if held_by else 'Answering the chats now.')
                for row in body.get('data', []):
                    with lock:
                        if row['chat_id'] in inflight:
                            continue
                        inflight.add(row['chat_id'])
                    pool.submit(work, row['chat_id'])
            time.sleep(POLL)
    except KeyboardInterrupt:
        log('Stopped.')
        return 0


if __name__ == '__main__':
    sys.exit(main())
