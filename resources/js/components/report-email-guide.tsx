import { Badge } from '@/components/ui/badge';

interface FieldDoc {
    name: string;
    required: string;
    description: React.ReactNode;
}

const FIELDS: FieldDoc[] = [
    {
        name: 'Sending server',
        required: 'Required to send',
        description: (
            <>
                The SMTP server of the mailbox the report is sent from — <code>smtp.gmail.com</code> for a Gmail account. Not an address of your own
                and not something this app provides.
            </>
        ),
    },
    {
        name: 'Port',
        required: 'Required',
        description: 'The port that server listens on. Almost always 587 or 465, and it has to agree with the encryption below.',
    },
    {
        name: 'Encryption',
        required: 'Required',
        description: 'How the connection is secured: STARTTLS (with 587), SSL/TLS (with 465), or None.',
    },
    {
        name: 'Username',
        required: 'Optional',
        description: 'The account to sign in as — usually the full email address. Leave it blank only for a server that needs no sign-in at all.',
    },
    {
        name: 'Password',
        required: 'Optional',
        description: (
            <>
                The password for that account — for Gmail and most large providers, an <strong>app password</strong> rather than the one you type into
                the website. Stored encrypted on this device and never shown again; leave it blank to keep the saved one.
            </>
        ),
    },
    {
        name: 'Send from',
        required: 'Required to send',
        description:
            'The address recipients see in the From: line. Usually the same as the username — many providers reject or rewrite a From: address the account does not own.',
    },
    {
        name: 'Sender name',
        required: 'Optional',
        description: 'The name shown beside that address. Blank uses your business name.',
    },
];

interface ProviderDoc {
    provider: string;
    host: string;
    port: string;
    encryption: string;
}

const PROVIDERS: ProviderDoc[] = [
    { provider: 'Gmail / Google Workspace', host: 'smtp.gmail.com', port: '587', encryption: 'STARTTLS' },
    { provider: 'Microsoft 365 / Outlook', host: 'smtp.office365.com', port: '587', encryption: 'STARTTLS' },
    { provider: 'Yahoo Mail', host: 'smtp.mail.yahoo.com', port: '465', encryption: 'SSL/TLS' },
];

/**
 * The three errors the send path can put on screen, each paired with what to
 * change. One entry per branch of App\Support\Email\ReportMailer::humanise() —
 * if that match ever grows an arm, this list is the other half of it.
 */
const FAILURES: { message: string; fix: React.ReactNode }[] = [
    {
        message: 'The mail server rejected the username or password.',
        fix: (
            <>
                The username is usually the <strong>full</strong> email address, not the part before the @. If the account has two-factor sign-in —
                and a Gmail account that can send this way always does — the password must be an <strong>app password</strong>; the ordinary account
                password is refused no matter how carefully it is typed.
            </>
        ),
    },
    {
        message: 'The secure connection to the mail server failed.',
        fix: (
            <>
                The port and the encryption disagree. Try <strong>587 with STARTTLS</strong> or <strong>465 with SSL/TLS</strong> — those are the two
                pairs nearly every provider accepts.
            </>
        ),
    },
    {
        message: 'The mail server could not be reached.',
        fix: (
            <>
                Either the server address is wrong, the port is closed, or this device has no internet. Check the address for a typo first, then
                whether anything else on the device can reach the network.
            </>
        ),
    },
];

function Mono({ children }: { children: React.ReactNode }) {
    return <code className="bg-muted rounded px-1 py-0.5 font-mono text-[0.8em]">{children}</code>;
}

function Callout({ title, tone = 'accent', children }: { title: string; tone?: 'accent' | 'warn'; children: React.ReactNode }) {
    const toneClasses = tone === 'warn' ? 'border-destructive/30 bg-destructive/5' : 'border-primary/30 bg-primary/5';
    return (
        <div className={`rounded-lg border p-3 ${toneClasses}`}>
            <p className="text-xs font-semibold tracking-wide uppercase">{title}</p>
            <div className="text-muted-foreground mt-1 text-sm">{children}</div>
        </div>
    );
}

function SectionHeading({ children }: { children: React.ReactNode }) {
    return <h3 className="text-sm font-semibold">{children}</h3>;
}

/**
 * The sender-credentials documentation, written for the person filling the form
 * in rather than for the person who wrote it.
 *
 * A panel with no trigger of its own, the same split
 * `components/inventory/import-guide.tsx` uses — the difference is that this one
 * IS shown in a Dialog. That guide's host is already a modal, and two stacked
 * Radix dialogs is more focus-trap trouble than the content is worth; Business
 * Settings is an ordinary page, so a dialog costs nothing and keeps a form the
 * Owner fills in once from carrying a wall of prose forever.
 *
 * Scope is the sender credentials alone. Recipients, the schedule and the cost
 * checkbox already carry inline help under each field, and the thing nobody can
 * guess is which mailbox the app is supposed to borrow.
 */
export default function ReportEmailGuide() {
    return (
        <div className="space-y-6 text-sm">
            <section className="space-y-3">
                <SectionHeading>How sending works</SectionHeading>
                <Callout title="It borrows a mailbox you already have">
                    This app sends no mail of its own — it signs in to an existing email account and sends as that account. Any mailbox will do: the
                    business's Gmail, an address from your web host, whatever you already use.
                </Callout>
                <Callout title="Set up on this device only" tone="warn">
                    The sending account is stored <strong>on this device</strong>, like the receipt printer and unlike the catalog or your staff. It
                    is never copied to the other branches and a sync can never overwrite it — so{' '}
                    <strong>every device that should email a report needs its own setup</strong>, and a device that is rebuilt needs it again.
                </Callout>
                <Callout title="Sending needs the internet" tone="warn">
                    Selling works offline; this does not. A send happens at the moment you ask for it or not at all, and it gives up after{' '}
                    <strong>15 seconds</strong> — the screen waits for it, so a slow server feels like a slow page.
                </Callout>
                <Callout title="The password is sealed to this install">
                    It is encrypted with this device's own key before it is stored, and never shown again once saved — the box stays blank and leaving
                    it blank keeps what is already there. Copying the database to another machine does not carry it across.
                </Callout>
            </section>

            <section className="space-y-3">
                <SectionHeading>The fields</SectionHeading>
                <p className="text-muted-foreground">
                    Your email provider publishes all of these — search for its name plus “SMTP settings”. Server and Send from become required as
                    soon as you set a schedule or fill in any of the others.
                </p>
                <div className="divide-y rounded-lg border">
                    {FIELDS.map((field) => (
                        <div key={field.name} className="grid gap-1 p-3 sm:grid-cols-[11rem_1fr] sm:gap-4">
                            <div className="flex min-w-0 flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">{field.name}</span>
                                {field.required === 'Optional' ? (
                                    <Badge variant="secondary" className="text-[0.65rem]">
                                        Optional
                                    </Badge>
                                ) : (
                                    <Badge className="text-[0.65rem]">{field.required}</Badge>
                                )}
                            </div>
                            <p className="text-muted-foreground min-w-0">{field.description}</p>
                        </div>
                    ))}
                </div>
            </section>

            <section className="space-y-3">
                <SectionHeading>Common providers</SectionHeading>
                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full border-collapse text-xs whitespace-nowrap">
                        <thead>
                            <tr className="bg-muted/60 text-muted-foreground">
                                <th className="border-r px-2.5 py-1.5 text-left font-medium">provider</th>
                                <th className="border-r px-2.5 py-1.5 text-left font-medium">sending server</th>
                                <th className="border-r px-2.5 py-1.5 text-left font-medium">port</th>
                                <th className="px-2.5 py-1.5 text-left font-medium">encryption</th>
                            </tr>
                        </thead>
                        <tbody>
                            {PROVIDERS.map((provider) => (
                                <tr key={provider.provider} className="border-t">
                                    <td className="border-r px-2.5 py-1.5">{provider.provider}</td>
                                    <td className="border-r px-2.5 py-1.5 font-mono">{provider.host}</td>
                                    <td className="border-r px-2.5 py-1.5 font-mono tabular-nums">{provider.port}</td>
                                    <td className="px-2.5 py-1.5">{provider.encryption}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <p className="text-muted-foreground">
                    Anything else — your own domain, a local provider — ask whoever supplies the mailbox. It is nearly always 587 with STARTTLS or 465
                    with SSL/TLS.
                </p>
            </section>

            <section className="space-y-3">
                <SectionHeading>Using a Gmail account</SectionHeading>
                <Callout title="Gmail needs an app password, not your password" tone="warn">
                    Google refuses an ordinary password from an app like this one. Turn on <strong>2-Step Verification</strong> on the Google account,
                    then generate an <strong>App password</strong> from its security settings and paste that sixteen-character string into{' '}
                    <strong>Password</strong>. Nothing else about the account changes, and the app password can be revoked on its own later.
                    <ul className="mt-1.5 list-disc space-y-1 pl-5">
                        <li>
                            <strong>Username</strong> is the full address — <Mono>shop@gmail.com</Mono>, not <Mono>shop</Mono>.
                        </li>
                        <li>
                            <strong>Send from</strong> must be that same address (or an alias Google has verified for it), or Gmail rewrites it to the
                            account's own address.
                        </li>
                        <li>
                            <Mono>smtp.gmail.com</Mono> on port <Mono>587</Mono> with <strong>STARTTLS</strong>.
                        </li>
                    </ul>
                </Callout>
            </section>

            <section className="space-y-3">
                <SectionHeading>Port and encryption go together</SectionHeading>
                <p className="text-muted-foreground">
                    These two are one setting in practice. Picking a port that disagrees with the encryption is the most common reason a first attempt
                    fails.
                </p>
                <ul className="text-muted-foreground list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>587 with STARTTLS</strong> — the usual pair, and what to try first.
                    </li>
                    <li>
                        <strong>465 with SSL/TLS</strong> — the other common pair; the connection is encrypted from the first byte.
                    </li>
                    <li>
                        <strong>None</strong> means the app does not <em>start</em> an encrypted connection — not that the connection stays in the
                        clear. If the server offers encryption it is still used. Only pick it for a mail server on your own network that asks for it.
                    </li>
                </ul>
            </section>

            <section className="space-y-3">
                <SectionHeading>Proving it works</SectionHeading>
                <p className="text-muted-foreground">
                    Credentials are only ever proven by trying them, which is what <strong>Send test email</strong> is for.
                </p>
                <ul className="text-muted-foreground list-disc space-y-1.5 pl-5">
                    <li>
                        <strong>Save first.</strong> The test uses what is stored, not what is on screen — an unsaved change is not part of it.
                    </li>
                    <li>
                        It goes to the <strong>default recipients</strong> above, not to you. Add at least one address, or there is nobody to send to
                        and the test refuses.
                    </li>
                    <li>A short message with no attachment and no figures — a recipient should not have to wonder whether it was a report.</li>
                    <li>If it arrives, the account works. Reports sent by hand and on a schedule take exactly this path.</li>
                </ul>
            </section>

            <section className="space-y-3">
                <SectionHeading>When it does not work</SectionHeading>
                <p className="text-muted-foreground">The message on screen names which of the three it is.</p>
                <ul className="space-y-2.5">
                    {FAILURES.map((failure) => (
                        <li key={failure.message} className="flex gap-2">
                            <span aria-hidden className="bg-destructive mt-1.5 size-1.5 shrink-0 rotate-45" />
                            <span>
                                <span className="font-medium">“{failure.message}”</span> <span className="text-muted-foreground">{failure.fix}</span>
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
