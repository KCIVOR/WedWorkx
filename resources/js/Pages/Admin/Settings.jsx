import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Alert, Button, Note, PayRow, Pill } from '../../Components/wb';
import { TextInput } from '../../Components/wb/forms';
import { AdminLayout, Panel, Select, SourcePill } from '../../Components/admin/ui';

/** A label with where its value comes from, the input, and an optional hint and error. */
function Row({ label, source, help, error, children }) {
    return (
        <div className="grid min-w-0 gap-[6px]">
            <div className="flex flex-wrap items-center gap-s-2">
                <label className="text-[13px] font-semibold text-wb-heading">{label}</label>
                {source && <SourcePill source={source} />}
            </div>
            {children}
            {help && <div className="text-[12.5px] text-wb-muted">{help}</div>}
            {error && <div role="alert" className="text-[12.5px] text-red-600">{error}</div>}
        </div>
    );
}

function Secret({ field, form, name }) {
    const f = field[name];
    return (
        <Row label={f.label} source={f.source} error={form.errors[name]}
            help={f.source === 'admin' ? 'It is never shown again. Type a new value to replace it.' : f.source === 'env' ? 'It comes from the .env file. Type a value here to use that instead.' : null}>
            <TextInput type="password" autoComplete="new-password" value={form.data[name]} invalid={!!form.errors[name]}
                placeholder={f.source === 'admin' ? 'Saved. Type to replace it' : f.source === 'env' ? 'Set in .env. Type to override' : 'Paste it here'}
                onChange={(e) => form.setData(name, e.target.value)} />
            {f.source === 'admin' && (
                <label className="flex items-center gap-s-2 text-[13px] text-wb-muted">
                    <input type="checkbox" className="accent-wb-accent" checked={form.data[`clear_${name}`]}
                        onChange={(e) => form.setData(`clear_${name}`, e.target.checked)} />
                    Remove the saved value and use the .env value
                </label>
            )}
        </Row>
    );
}

function Plain({ field, form, name, ...props }) {
    const f = field[name];
    return (
        <Row label={f.label} source={f.source} error={form.errors[name]} help={props.help}>
            <TextInput value={form.data[name]} invalid={!!form.errors[name]} onChange={(e) => form.setData(name, e.target.value)}
                autoComplete="off" {...props} help={undefined} />
        </Row>
    );
}

function TestResult({ what }) {
    const t = usePage().props.flash?.admin_test;
    if (!t || t.what !== what) return null;
    return <Alert tone={t.ok ? 'success' : 'danger'}>{t.message}</Alert>;
}

function CopyBox({ value }) {
    const [copied, setCopied] = useState(false);
    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
            setCopied(true);
        } catch {
            /* the text can still be selected by hand */
        }
    };
    return (
        <div className="flex flex-wrap items-center gap-s-2">
            <code className="min-w-0 flex-1 break-all rounded-[var(--r-md)] border border-wb-line bg-slate-50 px-s-3 py-s-2 font-mono text-[12.5px] text-wb-heading">{value}</code>
            <Button variant="outline" onClick={copy}>{copied ? 'Copied' : 'Copy'}</Button>
        </div>
    );
}

function EmailCard({ fields, base }) {
    const form = useForm({
        mail_host: fields.mail_host.value, mail_port: fields.mail_port.value, mail_encryption: fields.mail_encryption.value,
        mail_username: fields.mail_username.value, mail_password: '', clear_mail_password: false,
        mail_from_address: fields.mail_from_address.value, mail_from_name: fields.mail_from_name.value,
    });
    const save = (e) => {
        e.preventDefault();
        form.post(`${base}/settings/email`, { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, mail_password: '', clear_mail_password: false }) });
    };

    return (
        <Panel title="Email (SMTP)">
            <form onSubmit={save} className="grid gap-s-4">
                <div className="grid gap-s-4 md:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_minmax(0,1.4fr)]">
                    <Plain field={fields} form={form} name="mail_host" placeholder="smtp.gmail.com" />
                    <Plain field={fields} form={form} name="mail_port" inputMode="numeric" placeholder="465" />
                    <Row label={fields.mail_encryption.label} source={fields.mail_encryption.source}>
                        <Select value={form.data.mail_encryption} onChange={(e) => form.setData('mail_encryption', e.target.value)}>
                            <option value="ssl">SSL (port 465)</option>
                            <option value="tls">TLS (port 587)</option>
                        </Select>
                    </Row>
                </div>
                <div className="grid gap-s-4 sm:grid-cols-2">
                    <Plain field={fields} form={form} name="mail_username" autoComplete="off" />
                    <Secret field={fields} form={form} name="mail_password" />
                </div>
                <div className="grid gap-s-4 sm:grid-cols-2">
                    <Plain field={fields} form={form} name="mail_from_address" type="email" placeholder="hello@yourdomain.com" />
                    <Plain field={fields} form={form} name="mail_from_name" />
                </div>
                <TestResult what="mail" />
                <div className="flex flex-wrap gap-s-2">
                    <Button type="submit" disabled={form.processing}>Save email settings</Button>
                    <Button variant="outline" onClick={() => router.post(`${base}/settings/test/mail`, {}, { preserveScroll: true })}>Send a test email to me</Button>
                </div>
            </form>
        </Panel>
    );
}

function HitPayCard({ fields, base, webhookUrl }) {
    const form = useForm({
        hitpay_api_key: '', clear_hitpay_api_key: false, hitpay_salt: '', clear_hitpay_salt: false,
        hitpay_base_url: fields.hitpay_base_url.value,
    });
    const save = (e) => {
        e.preventDefault();
        form.post(`${base}/settings/hitpay`, { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, hitpay_api_key: '', hitpay_salt: '', clear_hitpay_api_key: false, clear_hitpay_salt: false }) });
    };

    return (
        <Panel title="HitPay (payments)">
            <form onSubmit={save} className="grid gap-s-4">
                <Plain field={fields} form={form} name="hitpay_base_url" placeholder="https://api.hit-pay.com/v1"
                    help="Sandbox: https://api.sandbox.hit-pay.com/v1. Live: https://api.hit-pay.com/v1." />
                <div className="grid gap-s-4 sm:grid-cols-2">
                    <Secret field={fields} form={form} name="hitpay_api_key" />
                    <Secret field={fields} form={form} name="hitpay_salt" />
                </div>
                <Row label="Webhook address" help="Paste this into HitPay (event: payment_request.completed).">
                    <CopyBox value={webhookUrl} />
                </Row>
                <TestResult what="hitpay" />
                <div className="flex flex-wrap gap-s-2">
                    <Button type="submit" disabled={form.processing}>Save HitPay settings</Button>
                    <Button variant="outline" onClick={() => router.post(`${base}/settings/test/hitpay`, {}, { preserveScroll: true })}>Check the key</Button>
                </div>
            </form>
        </Panel>
    );
}

function HermesCard({ fields, base }) {
    const token = usePage().props.flash?.admin_token;
    const f = fields.hermes_token;
    const generate = () => {
        if (f.source !== 'unset' && !window.confirm('The current token stops working right away. The Hermes agents need the new one. Continue?')) return;
        router.post(`${base}/settings/hermes/token`, {}, { preserveScroll: true });
    };

    return (
        <Panel title="Hermes agents (API token)" action={<SourcePill source={f.source} />}>
            <div className="grid gap-s-4">
                <p className="text-[14px] leading-[1.55] text-wb-muted">
                    The agents send this token with every request to <code className="font-mono text-[12.5px]">{window.location.origin}/api/hermes</code>. Create a new one and give it to the agents.
                </p>
                {token && (
                    <div className="grid gap-s-2">
                        <Alert tone="warning" title="Copy this token now">It is shown only this once. Closing or reloading this page hides it for good.</Alert>
                        <CopyBox value={token} />
                    </div>
                )}
                <div className="flex flex-wrap gap-s-2">
                    <Button onClick={generate}>{f.source === 'unset' ? 'Create a token' : 'Create a new token'}</Button>
                    {f.source === 'admin' && (
                        <Button variant="outline" onClick={() => window.confirm('Remove the saved token? Hermes stops working unless .env has one.') && router.delete(`${base}/settings/hermes/token`, { preserveScroll: true })}>
                            Remove the saved token
                        </Button>
                    )}
                </div>
            </div>
        </Panel>
    );
}

function AgentChatCard({ fields, base, agent }) {
    const form = useForm({ chat_mode_registration: fields.chat_mode_registration.value, chat_mode_website: fields.chat_mode_website.value });
    const save = (e) => {
        e.preventDefault();
        form.post(`${base}/settings/chat`, { preserveScroll: true });
    };
    const seen = agent.seconds_ago == null ? 'Hermes has not asked for chats yet.' : agent.online ? `Hermes is listening (last asked ${agent.seconds_ago}s ago).` : `Hermes is not listening (last asked ${agent.seconds_ago < 120 ? `${agent.seconds_ago}s` : `${Math.round(agent.seconds_ago / 60)} min`} ago).`;
    const pick = (name, label) => (
        <Row label={label} source={fields[name].source}>
            <Select value={form.data[name]} onChange={(e) => form.setData(name, e.target.value)}>
                <option value="system">The system (fixed questions)</option>
                <option value="hermes">The Hermes agent (conversation)</option>
            </Select>
        </Row>
    );

    return (
        <Panel title="Who asks the questions" action={<Pill tone={agent.online ? 'success' : 'muted'}>{agent.online ? `Hermes online${agent.consumer && agent.consumer !== 'legacy' ? ` via ${agent.consumer}` : ''}` : 'Hermes offline'}</Pill>}>
            <form onSubmit={save} className="grid gap-s-4">
                <p className="text-[14px] leading-[1.55] text-wb-muted">
                    With "Hermes", the agent has the conversation inside the same chat on the website. If Hermes is not listening, or does not answer within {agent.timeout} seconds,
                    the fixed questions take over without the supplier noticing, and it is written on the Activity page. {seen}
                </p>
                <div className="grid gap-s-4 sm:grid-cols-2">
                    {pick('chat_mode_registration', 'Registration chat (Bot 1) is asked by')}
                    {pick('chat_mode_website', 'Website chat (Bot 2) is asked by')}
                </div>
                <div><Button type="submit" disabled={form.processing}>Save</Button></div>
            </form>
        </Panel>
    );
}

function AiCard({ fields, base }) {
    const form = useForm({ ai_enabled: !!fields.ai_enabled.value, ai_api_key: '', clear_ai_api_key: false, ai_model: fields.ai_model.value });
    const save = (e) => {
        e.preventDefault();
        form.post(`${base}/settings/ai`, { preserveScroll: true, onSuccess: () => form.setData({ ...form.data, ai_api_key: '', clear_ai_api_key: false }) });
    };

    return (
        <Panel title="AI wording (optional)">
            <form onSubmit={save} className="grid gap-s-4">
                <label className="flex items-center gap-s-3 text-[14px] text-wb-heading">
                    <input type="checkbox" className="h-[18px] w-[18px] accent-wb-accent" checked={form.data.ai_enabled}
                        onChange={(e) => form.setData('ai_enabled', e.target.checked)} />
                    <span><strong>Let the assistant reword questions.</strong> <span className="text-wb-muted">Off means the scripted questions only.</span></span>
                </label>
                <div className="grid gap-s-4 sm:grid-cols-2">
                    <Secret field={fields} form={form} name="ai_api_key" />
                    <Plain field={fields} form={form} name="ai_model" placeholder="claude-haiku-5-5" />
                </div>
                <TestResult what="ai" />
                <div className="flex flex-wrap gap-s-2">
                    <Button type="submit" disabled={form.processing}>Save AI settings</Button>
                    <Button variant="outline" onClick={() => router.post(`${base}/settings/test/ai`, {}, { preserveScroll: true })}>Check the key</Button>
                </div>
            </form>
        </Panel>
    );
}

function CampaignCard({ campaign, base }) {
    const form = useForm({ price: campaign.price, invite_expiry_hours: campaign.invite_expiry_hours, grace_minutes: campaign.grace_minutes });
    const lock = !campaign.editable;
    const save = (e) => {
        e.preventDefault();
        form.post(`${base}/settings/campaign`, { preserveScroll: true });
    };
    const num = (name, label, help) => (
        <Row label={label} help={help} error={form.errors[name]}>
            <TextInput inputMode="decimal" disabled={lock} value={form.data[name]} invalid={!!form.errors[name]} onChange={(e) => form.setData(name, e.target.value)} />
        </Row>
    );

    return (
        <Panel title="Campaign">
            <form onSubmit={save} className="grid gap-s-4">
                {lock && <Note icon="info">Registration has started ({campaign.slots_taken} of {campaign.slot_limit} slots taken), so these values are locked to keep the deal the same for everyone.</Note>}
                <div className="grid gap-s-4 sm:grid-cols-3">
                    {num('price', 'Price (₱)', 'What each winner pays.')}
                    {num('invite_expiry_hours', 'Invitation lasts (hours)', 'Time to answer and pay.')}
                    {num('grace_minutes', 'Grace period (minutes)', 'For a payment started before the deadline.')}
                </div>
                <div><Button type="submit" disabled={lock || form.processing}>Save campaign</Button></div>
            </form>
        </Panel>
    );
}

export default function Settings({ fields, campaign, agent, webhook_url, dev_mode, app_env }) {
    const base = `/${usePage().props.admin.path}`;

    return (
        <AdminLayout title="Settings" current="/settings">
            <Head title="Settings" />
            <Note icon="info">
                Values saved here are stored encrypted and take priority over the <code className="font-mono text-[12.5px]">.env</code> file. Secrets are never shown again after saving.
                Database details, the app key and dev mode stay in <code className="font-mono text-[12.5px]">.env</code>.
            </Note>

            <EmailCard fields={fields} base={base} />
            <HitPayCard fields={fields} base={base} webhookUrl={webhook_url} />
            <HermesCard fields={fields} base={base} />
            <AgentChatCard fields={fields} base={base} agent={agent} />
            <AiCard fields={fields} base={base} />
            {campaign && <CampaignCard campaign={campaign} base={base} />}

            <Panel title="This installation (read only)">
                <div className="grid">
                    <PayRow label="Environment">{app_env}</PayRow>
                    <PayRow label="Dev mode"><Pill tone={dev_mode ? 'warning' : 'muted'}>{dev_mode ? 'On: payments and Hermes are simulated' : 'Off'}</Pill></PayRow>
                    {campaign && <PayRow label="Winner slots">{campaign.slot_limit}</PayRow>}
                </div>
            </Panel>
        </AdminLayout>
    );
}
