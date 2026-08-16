import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useMemo } from 'react';

// IANA identifiers from the runtime (Chromium under NativePHP), with the
// current value appended in case it isn't in the runtime's list.
export default function TimezoneSelect({ id, value, onChange }: { id?: string; value: string; onChange: (value: string) => void }) {
    const timezones = useMemo(() => {
        const list = Intl.supportedValuesOf('timeZone') as string[];

        return value && !list.includes(value) ? [...list, value].sort() : list;
    }, [value]);

    return (
        <Select value={value} onValueChange={onChange}>
            <SelectTrigger id={id} className="w-72">
                <SelectValue placeholder="Choose a timezone" />
            </SelectTrigger>
            <SelectContent>
                {timezones.map((tz) => (
                    <SelectItem key={tz} value={tz}>
                        {tz.replaceAll('_', ' ')}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}
