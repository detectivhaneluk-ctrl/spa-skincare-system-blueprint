import dagre from '@dagrejs/dagre';
import type { Edge, Node } from '@xyflow/react';
import { Position } from '@xyflow/react';
import type { OlliraTreeNode } from '../types';

const DIM = {
  root: { w: 200, h: 56 },
  category: { w: 252, h: 92 },
  service: { w: 276, h: 118 },
} as const;

function dimFor(n: OlliraTreeNode) {
  if (n.type === 'root') return DIM.root;
  if (n.type === 'category') return DIM.category;
  return DIM.service;
}

type Flat = { node: OlliraTreeNode; parentId: string | null };

/**
 * Visible nodes for the map only — synthetic API `root` is skipped so each top-level
 * category (or uncategorized block) is its own visual root with parentId null.
 */
function flattenVisible(apiRoot: OlliraTreeNode, expanded: Set<string>): Flat[] {
  const out: Flat[] = [];

  const walk = (n: OlliraTreeNode, parentId: string | null) => {
    if (n.type === 'root') {
      for (const c of n.children ?? []) walk(c, null);
      return;
    }
    out.push({ node: n, parentId });
    if (n.type === 'service') return;
    if (!expanded.has(n.id)) return;
    for (const c of n.children ?? []) walk(c, n.id);
  };

  walk(apiRoot, null);
  return out;
}

function flowType(n: OlliraTreeNode): string {
  if (n.type === 'root') return 'olliraRoot';
  if (n.type === 'category') return 'olliraCategory';
  return 'olliraService';
}

/** Visible subtree for one top-level branch (category / uncategorized). */
function collectSubtreeForColumn(node: OlliraTreeNode, expanded: Set<string>): OlliraTreeNode[] {
  const out: OlliraTreeNode[] = [];
  const walk = (n: OlliraTreeNode) => {
    out.push(n);
    if (n.type === 'service') return;
    if (!expanded.has(n.id)) return;
    for (const c of n.children ?? []) walk(c);
  };
  walk(node);
  return out;
}

const DAGRE_GRAPH = {
  rankdir: 'LR' as const,
  align: 'UL' as const,
  nodesep: 44,
  ranksep: 76,
  marginx: 20,
  marginy: 24,
  edgesep: 18,
};

function layoutSubtreeWithDagre(subNodes: OlliraTreeNode[]): Map<string, { cx: number; cy: number }> {
  const subIds = new Set(subNodes.map((n) => n.id));
  const g = new dagre.graphlib.Graph();
  g.setDefaultEdgeLabel(() => ({}));
  g.setGraph({ ...DAGRE_GRAPH });

  for (const n of subNodes) {
    const { w, h } = dimFor(n);
    g.setNode(n.id, { width: w, height: h });
  }
  for (const n of subNodes) {
    for (const c of n.children ?? []) {
      if (subIds.has(c.id)) g.setEdge(n.id, c.id);
    }
  }
  dagre.layout(g);

  const m = new Map<string, { cx: number; cy: number }>();
  for (const n of subNodes) {
    const d = g.node(n.id);
    m.set(n.id, { cx: d.x, cy: d.y });
  }
  return m;
}

/**
 * Each top-level category (and uncategorized) is one LR mini-tree; columns are packed
 * left-to-right with no shared synthetic parent node on the canvas.
 */
function layoutChainedColumns(topLevel: OlliraTreeNode[], expanded: Set<string>): Map<string, { cx: number; cy: number }> {
  const positions = new Map<string, { cx: number; cy: number }>();
  const COLUMN_GAP = 56;
  let xCursor = 0;

  for (const tl of topLevel) {
    const subNodes = collectSubtreeForColumn(tl, expanded);
    if (subNodes.length === 0) continue;

    const local = layoutSubtreeWithDagre(subNodes);

    let colMinLeft = Infinity;
    let colMaxRight = -Infinity;
    let colMinTop = Infinity;
    for (const n of subNodes) {
      const p = local.get(n.id)!;
      const { w, h } = dimFor(n);
      colMinLeft = Math.min(colMinLeft, p.cx - w / 2);
      colMaxRight = Math.max(colMaxRight, p.cx + w / 2);
      colMinTop = Math.min(colMinTop, p.cy - h / 2);
    }

    const shiftX = xCursor - colMinLeft;
    const shiftY = -colMinTop;

    for (const n of subNodes) {
      const p = local.get(n.id)!;
      positions.set(n.id, { cx: p.cx + shiftX, cy: p.cy + shiftY });
    }

    xCursor += colMaxRight - colMinLeft + COLUMN_GAP;
  }

  return positions;
}

function buildNodesAndEdges(
  flat: Flat[],
  positions: Map<string, { cx: number; cy: number }>,
  idsInView: Set<string>
): { nodes: Node[]; edges: Edge[] } {
  const nodes: Node[] = [];
  const edges: Edge[] = [];
  const byId = new Map(flat.map((f) => [f.node.id, f.node]));

  for (const { node, parentId } of flat) {
    const p = positions.get(node.id);
    if (!p) continue;
    const { w, h } = dimFor(node);
    const hasKids = (node.children?.length ?? 0) > 0;
    const isCollapsed =
      node.type === 'category' && hasKids && (node.children ?? []).some((c) => !idsInView.has(c.id));

    nodes.push({
      id: node.id,
      type: flowType(node),
      position: { x: p.cx - w / 2, y: p.cy - h / 2 },
      sourcePosition: Position.Right,
      targetPosition: Position.Left,
      draggable: node.type === 'category',
      data: {
        treeNode: node,
        parentId,
        isCollapsed,
        hasChildren: hasKids,
      },
    });

    if (parentId) {
      const parentNode = byId.get(parentId);
      const categoryToCategory =
        parentNode?.type === 'category' && node.type === 'category';
      edges.push({
        id: `e-${parentId}-${node.id}`,
        source: parentId,
        target: node.id,
        type: 'default',
        reconnectable: categoryToCategory ? 'source' : false,
      });
    }
  }

  return { nodes, edges };
}

export function layoutTreeToFlow(
  apiRoot: OlliraTreeNode,
  expanded: Set<string>
): { nodes: Node[]; edges: Edge[] } {
  const flat = flattenVisible(apiRoot, expanded);
  const idsInView = new Set(flat.map((f) => f.node.id));
  const positions = layoutChainedColumns(apiRoot.children ?? [], expanded);
  return buildNodesAndEdges(flat, positions, idsInView);
}
