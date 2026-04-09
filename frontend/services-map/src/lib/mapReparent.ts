/** Flow node id `cat-123` → DB id; uncategorized / invalid → null */
export function parseCategoryDbId(nodeId: string): number | null {
  if (nodeId === 'cat-uncategorized') return null;
  if (!nodeId.startsWith('cat-')) return null;
  const n = parseInt(nodeId.slice(4), 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}
