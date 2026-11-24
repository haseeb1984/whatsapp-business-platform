type Props = { errors: Record<string, string> };

export default function FormErrorSummary({ errors }: Props) {
    const messages = Object.entries(errors).filter(([, message]) => Boolean(message));

    if (messages.length === 0) return null;

    return (
        <div role="alert" aria-live="assertive" className="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p className="font-semibold">Please review the following fields:</p>
            <ul className="mt-2 list-disc space-y-1 pl-5">
                {messages.map(([field, message]) => <li key={field}>{message}</li>)}
            </ul>
        </div>
    );
}
