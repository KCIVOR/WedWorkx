import { Head, useForm, usePage } from '@inertiajs/react';
import { Alert, Button, CardIcon, CardText, CardTitle, FieldError } from '../../Components/wb';
import { Field, TextInput } from '../../Components/wb/forms';

export default function Login({ notice }) {
    const { dev } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({ email: '', password: '' });

    const submit = (e) => {
        e.preventDefault();
        post(`${window.location.pathname}`, { onFinish: () => setData('password', '') });
    };

    return (
        <div className="grid min-h-screen place-items-center bg-wb-canvas px-s-4 py-s-8 font-body text-wb-text">
            <Head title="Admin sign in" />
            <form onSubmit={submit} className="grid w-full max-w-[400px] gap-s-4 rounded-r-card border border-wb-line bg-white p-s-6 shadow-sh-md">
                <div className="grid justify-items-center gap-s-2 text-center">
                    <CardIcon name="rings" />
                    <CardTitle>WedWorks Admin</CardTitle>
                    <CardText>Sign in to see suppliers, payments and settings.</CardText>
                    {dev && <span className="rounded-r-pill bg-amber-50 px-s-3 py-s-1 text-[11px] font-semibold uppercase tracking-[.08em] text-amber-700">Dev mode</span>}
                </div>

                {notice && <Alert tone="info">{notice}</Alert>}

                <Field label="Email">
                    <TextInput type="email" autoComplete="username" autoFocus required value={data.email} invalid={!!errors.email}
                        onChange={(e) => setData('email', e.target.value)} />
                </Field>
                <Field label="Password">
                    <TextInput type="password" autoComplete="current-password" required value={data.password}
                        onChange={(e) => setData('password', e.target.value)} />
                </Field>
                <FieldError>{errors.email}</FieldError>

                <Button type="submit" block disabled={processing}>
                    {processing ? 'Signing in...' : 'Sign in'}
                </Button>
            </form>
        </div>
    );
}
