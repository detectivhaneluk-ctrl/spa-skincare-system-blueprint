import type { OlliraTreeNode } from '../types';

export function findNode(root: OlliraTreeNode, id: string): OlliraTreeNode | null {
  if (root.id === id) return root;
  for (const c of root.children ?? []) {
    const f = findNode(c, id);
    if (f) return f;
  }
  return null;
}

/** Categories + root: expanded branch shows children */
export function defaultExpandedCategoryIds(root: OlliraTreeNode): Set<string> {
  const s = new Set<string>();
  const walk = (n: OlliraTreeNode) => {
    if (n.type === 'category') s.add(n.id);
    (n.children ?? []).forEach(walk);
  };
  walk(root);
  return s;
}

export type CategoryPickerRow = { id: string; name: string; depth: number };

/** DFS list of categories for jump-to dropdown (indent via depth). */
export function listCategoriesForPicker(root: OlliraTreeNode): CategoryPickerRow[] {
  const out: CategoryPickerRow[] = [];
  const walk = (n: OlliraTreeNode, depth: number) => {
    if (n.type === 'root') {
      (n.children ?? []).forEach((c) => walk(c, depth));
      return;
    }
    if (n.type === 'category') {
      out.push({ id: n.id, name: n.name, depth });
      (n.children ?? []).forEach((c) => walk(c, depth + 1));
    }
  };
  walk(root, 0);
  return out;
}

/**
 * Category ids that must be expanded so flow node `targetId` is listed (see flattenVisible).
 * Top-level category → []; nested category → ancestors only; service → all categories on path.
 */
export function categoryIdsRequiredExpandedForTarget(root: OlliraTreeNode, targetId: string): string[] | null {
  const path: OlliraTreeNode[] = [];
  const dfs = (n: OlliraTreeNode): boolean => {
    path.push(n);
    if (n.id === targetId) {
      return true;
    }
    for (const c of n.children ?? []) {
      if (dfs(c)) return true;
    }
    path.pop();
    return false;
  };
  if (!dfs(root)) {
    return null;
  }
  const chain = path.filter((n) => n.type === 'category');
  const target = path[path.length - 1];
  if (target.type === 'service') {
    return chain.map((c) => c.id);
  }
  return chain.slice(0, -1).map((c) => c.id);
}
