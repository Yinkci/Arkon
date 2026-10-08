import { Head, router } from '@inertiajs/react';
import { Button } from '@/Components/ui';
import { AuthFrame } from './Login';

export default function NoSite() {
    return (
        <AuthFrame>
            <Head title="No site" />
            <h1 className="text-xl font-semibold tracking-tight">No site access</h1>
            <p className="mt-2 text-[0.8125rem] text-muted">Your account is not a member of any site. Ask a site owner to add you.</p>
            <Button icon="logout" className="mt-6" onClick={() => router.post('/logout')}>
                Sign out
            </Button>
        </AuthFrame>
    );
}
