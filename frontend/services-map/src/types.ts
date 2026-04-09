export type OlliraNodeType = 'root' | 'category' | 'service';

/** Nested tree from API / mock */
export type OlliraTreeNode = {
  id: string;
  type: OlliraNodeType;
  name: string;
  /** Service leaf only */
  durationMinutes?: number;
  price?: number;
  currency?: string;
  sku?: string;
  children?: OlliraTreeNode[];
};

export type NodeAction =
  | 'add-child'
  | 'edit'
  | 'delete'
  | 'toggle-expand'
  /** Map saved a category parent change; host may reload to refresh tree JSON */
  | 'reparent-success';

export type ServicesMapMountOptions = {
  tree: OlliraTreeNode;
  /** If omitted, all categories start expanded */
  initiallyExpandedIds?: string[] | null;
  height?: string;
  onAction?: (payload: { nodeId: string; action: NodeAction; node: OlliraTreeNode }) => void;
  /** POST JSON reparent (same permission as category edit). CSRF via X-CSRF-TOKEN header. */
  categoryReparentUrl?: string | null;
  csrfToken?: string | null;
};
