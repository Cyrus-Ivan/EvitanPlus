import { Badge } from '@/components/ui/badge';

/**
 * Whether a record is live or put away.
 *
 * Four pages rendered this pill and each read a different field to decide:
 * `archived_at` on items and categories, `deleted_at` on users, a boolean
 * `archived` on inventory rows. That is not an inconsistency to fix in the data
 * — a user is soft-DELETED and an item is ARCHIVED, and both are non-negotiable
 * invariants with their own reasons — but on screen it is one fact, and it
 * should look like one fact.
 *
 * So the prop is a boolean the caller derives, not a field name this component
 * knows: `<ArchivedBadge archived={Boolean(user.deleted_at)} />`.
 */
export function ArchivedBadge({ archived }: { archived: boolean }) {
    return archived ? <Badge variant="secondary">Archived</Badge> : <Badge>Active</Badge>;
}
