import {
  Background,
  Controls,
  MiniMap,
  ReactFlow,
  ReactFlowProvider,
  reconnectEdge,
  useEdgesState,
  useNodesState,
  useReactFlow,
} from '@xyflow/react';
import type { Connection, Edge } from '@xyflow/react';
import type { MouseEvent as ReactMouseEvent, RefObject } from 'react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ActionsProvider } from './context/actionsContext';
import { CategoryNode } from './components/CategoryNode';
import { ServiceNode } from './components/ServiceNode';
import {
  categoryIdsRequiredExpandedForTarget,
  defaultExpandedCategoryIds,
  findNode,
  listCategoriesForPicker,
  type CategoryPickerRow,
} from './lib/treeUtils';
import { layoutTreeToFlow } from './lib/treeToFlow';
import { parseCategoryDbId } from './lib/mapReparent';
import {
  isElementInFullscreen,
  isFullscreenAvailable,
  toggleElementFullscreen,
} from './lib/fullscreen';
import type { NodeAction, OlliraTreeNode, ServicesMapMountOptions } from './types';

const nodeTypes = {
  olliraCategory: CategoryNode,
  olliraService: ServiceNode,
};

async function postCategoryReparent(
  url: string,
  csrfToken: string,
  categoryId: number,
  parentId: number
): Promise<{ ok: boolean; error?: string }> {
  try {
    const res = await fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
      },
      body: JSON.stringify({ id: categoryId, parent_id: parentId }),
    });
    const data = (await res.json()) as { ok?: boolean; error?: string };
    if (!res.ok || !data.ok) {
      return { ok: false, error: data.error || `HTTP ${res.status}` };
    }
    return { ok: true };
  } catch {
    return { ok: false, error: 'Network error' };
  }
}

/** Fit once when nodes first appear (no refit on each expand). */
function FitOnceOverlay({ nodeCount }: { nodeCount: number }) {
  const { fitView } = useReactFlow();
  const done = useRef(false);
  useEffect(() => {
    if (done.current || nodeCount === 0) return;
    done.current = true;
    const id = requestAnimationFrame(() => fitView({ padding: 0.14, duration: 280 }));
    return () => cancelAnimationFrame(id);
  }, [nodeCount, fitView]);
  return null;
}

function FocusCategoryEffect({
  pendingFocusNodeId,
  nodeIdsDigest,
  onFocusApplied,
}: {
  pendingFocusNodeId: string | null;
  /** Stable-ish digest so we re-run when layout nodes change */
  nodeIdsDigest: string;
  onFocusApplied: () => void;
}) {
  const { fitView, getNode } = useReactFlow();
  useEffect(() => {
    if (!pendingFocusNodeId) return;
    if (!getNode(pendingFocusNodeId)) return;
    const id = requestAnimationFrame(() => {
      fitView({
        nodes: [{ id: pendingFocusNodeId }],
        padding: 0.34,
        duration: 480,
        maxZoom: 1.2,
        minZoom: 0.12,
      });
      onFocusApplied();
    });
    return () => cancelAnimationFrame(id);
  }, [pendingFocusNodeId, nodeIdsDigest, fitView, getNode, onFocusApplied]);
  return null;
}

/** Refit the graph when the map container enters or leaves fullscreen (viewport size change). */
function FullscreenFitEffect({ containerRef }: { containerRef: RefObject<HTMLElement | null> }) {
  const { fitView } = useReactFlow();
  useEffect(() => {
    const onChange = () => {
      requestAnimationFrame(() => fitView({ padding: 0.14, duration: 320 }));
    };
    document.addEventListener('fullscreenchange', onChange);
    document.addEventListener('webkitfullscreenchange', onChange);
    return () => {
      document.removeEventListener('fullscreenchange', onChange);
      document.removeEventListener('webkitfullscreenchange', onChange);
    };
  }, [fitView]);
  useEffect(() => {
    const el = containerRef.current;
    if (!el) return;
    const ro = new ResizeObserver(() => {
      if (!isElementInFullscreen(el)) return;
      requestAnimationFrame(() => fitView({ padding: 0.14, duration: 200 }));
    });
    ro.observe(el);
    return () => ro.disconnect();
  }, [containerRef, fitView]);
  return null;
}

type FlowBodyProps = {
  laid: { nodes: ReturnType<typeof layoutTreeToFlow>['nodes']; edges: ReturnType<typeof layoutTreeToFlow>['edges'] };
  tree: OlliraTreeNode;
  onAction: ServicesMapMountOptions['onAction'];
  categoryReparentUrl: string;
  csrfToken: string;
  setPositionOverrides: React.Dispatch<
    React.SetStateAction<Record<string, { x: number; y: number }>>
  >;
  pendingFocusNodeId: string | null;
  onFocusApplied: () => void;
  mapContainerRef: RefObject<HTMLDivElement | null>;
};

function FlowBody({
  laid,
  tree,
  onAction,
  categoryReparentUrl,
  csrfToken,
  setPositionOverrides,
  pendingFocusNodeId,
  onFocusApplied,
  mapContainerRef,
}: FlowBodyProps) {
  const [nodes, setNodes, onNodesChange] = useNodesState(laid.nodes);
  const [edges, setEdges, onEdgesChange] = useEdgesState(laid.edges);

  const nodeIdsDigest = useMemo(() => nodes.map((n) => n.id).join('|'), [nodes]);

  useEffect(() => {
    setNodes(laid.nodes);
    setEdges(laid.edges);
  }, [laid, setNodes, setEdges]);

  const onNodeDragStop = useCallback(
    (_: ReactMouseEvent, node: { id: string; type?: string; position: { x: number; y: number } }) => {
      if (node.type !== 'olliraCategory') return;
      setPositionOverrides((prev) => ({ ...prev, [node.id]: { ...node.position } }));
    },
    [setPositionOverrides]
  );

  const onReconnect = useCallback(
    (oldEdge: Edge, newConnection: Connection) => {
      const childFlowId = oldEdge.target;
      const newParentFlowId = newConnection.source;
      const childDb = parseCategoryDbId(childFlowId);
      const parentDb = parseCategoryDbId(newParentFlowId);
      if (childDb === null || parentDb === null) return;
      if (childDb === parentDb) return;
      if (!categoryReparentUrl || !csrfToken) {
        window.alert('Reparent is not configured (missing URL or CSRF).');
        return;
      }

      setEdges((currentEds) => {
        const before = currentEds;
        const next = reconnectEdge(oldEdge, newConnection, currentEds);
        void (async () => {
          const result = await postCategoryReparent(categoryReparentUrl, csrfToken, childDb, parentDb);
          if (!result.ok) {
            setEdges(before);
            window.alert(result.error || 'Could not move category');
            return;
          }
          const n = findNode(tree, childFlowId);
          if (n) {
            onAction?.({ nodeId: childFlowId, action: 'reparent-success', node: n });
          }
        })();
        return next;
      });
    },
    [categoryReparentUrl, csrfToken, onAction, setEdges, tree]
  );

  return (
    <ReactFlow
      nodes={nodes}
      edges={edges}
      onNodesChange={onNodesChange}
      onEdgesChange={onEdgesChange}
      onNodeDragStop={onNodeDragStop}
      onReconnect={onReconnect}
      nodeTypes={nodeTypes}
      nodesDraggable
      nodesConnectable={false}
      edgesReconnectable
      elementsSelectable
      zoomOnScroll
      zoomOnPinch
      minZoom={0.08}
      maxZoom={2}
      fitView
      proOptions={{ hideAttribution: true }}
      defaultEdgeOptions={{
        type: 'default',
        style: { stroke: '#94a3b8', strokeWidth: 2 },
      }}
    >
      <Background gap={20} size={1.2} color="#cbd5e1" />
      <Controls showInteractive />
      <MiniMap
        nodeStrokeWidth={2}
        zoomable
        pannable
        className="!rounded-lg !border !border-slate-200 !bg-white/90"
      />
      <FitOnceOverlay nodeCount={laid.nodes.length} />
      <FocusCategoryEffect
        pendingFocusNodeId={pendingFocusNodeId}
        nodeIdsDigest={nodeIdsDigest}
        onFocusApplied={onFocusApplied}
      />
      <FullscreenFitEffect containerRef={mapContainerRef} />
    </ReactFlow>
  );
}

function CategoryJumpToolbar({
  rows,
  onPick,
  fullscreenSupported,
  isFullscreen,
  onToggleFullscreen,
}: {
  rows: CategoryPickerRow[];
  onPick: (flowNodeId: string) => void;
  fullscreenSupported: boolean;
  isFullscreen: boolean;
  onToggleFullscreen: () => void;
}) {
  const [selKey, setSelKey] = useState(0);
  return (
    <div className="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-slate-200 bg-white px-3 py-2">
      <div className="flex min-w-0 flex-1 items-center gap-2">
        <label htmlFor="ollira-map-jump-cat" className="shrink-0 text-xs font-medium text-slate-600">
          Focus category
        </label>
        <select
          key={selKey}
          id="ollira-map-jump-cat"
          className="max-w-[min(100%,20rem)] min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-800 shadow-sm focus:border-slate-400 focus:outline-none focus:ring-1 focus:ring-slate-300"
          defaultValue=""
          onChange={(e) => {
            const v = e.target.value;
            if (!v) return;
            onPick(v);
            setSelKey((k) => k + 1);
          }}
        >
          <option value="">Choose a category…</option>
        {rows.map((r) => (
          <option key={r.id} value={r.id}>
            {'\u2013'.repeat(Math.max(0, r.depth)) + (r.depth > 0 ? ' ' : '') + r.name}
          </option>
        ))}
        </select>
      </div>
      <p
        className="hidden max-w-md text-[11px] leading-snug text-slate-500 md:block"
        title="Drag a category node to reposition. Drag the source end of a category-to-category link onto another category to reparent."
      >
        Drag categories to reposition. Drag the <strong className="font-semibold text-slate-700">source</strong> end of a
        category link onto another category to reparent.
      </p>
      {fullscreenSupported ? (
        <button
          type="button"
          className="shrink-0 rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-xs font-medium text-slate-700 shadow-sm hover:bg-slate-50"
          onClick={onToggleFullscreen}
          title={isFullscreen ? 'Exit full screen (Esc)' : 'Use the entire screen for the map'}
        >
          {isFullscreen ? 'Exit full screen' : 'Full screen'}
        </button>
      ) : null}
    </div>
  );
}

function InnerCanvas({
  tree,
  initiallyExpandedIds,
  onAction,
  categoryReparentUrl,
  csrfToken,
}: Omit<ServicesMapMountOptions, 'height'>) {
  const mapContainerRef = useRef<HTMLDivElement>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const fullscreenSupported = useMemo(() => isFullscreenAvailable(), []);

  const syncFullscreen = useCallback(() => {
    const el = mapContainerRef.current;
    setIsFullscreen(isElementInFullscreen(el));
  }, []);

  useEffect(() => {
    document.addEventListener('fullscreenchange', syncFullscreen);
    document.addEventListener('webkitfullscreenchange', syncFullscreen);
    syncFullscreen();
    return () => {
      document.removeEventListener('fullscreenchange', syncFullscreen);
      document.removeEventListener('webkitfullscreenchange', syncFullscreen);
    };
  }, [syncFullscreen]);

  const onToggleFullscreen = useCallback(async () => {
    const el = mapContainerRef.current;
    if (!el || !fullscreenSupported) return;
    try {
      await toggleElementFullscreen(el);
    } catch {
      /* denied or unsupported */
    }
  }, [fullscreenSupported]);

  const [pendingFocusNodeId, setPendingFocusNodeId] = useState<string | null>(null);
  const clearPendingFocus = useCallback(() => setPendingFocusNodeId(null), []);

  const [expanded, setExpanded] = useState<Set<string>>(() => {
    if (initiallyExpandedIds && initiallyExpandedIds.length > 0) {
      return new Set(initiallyExpandedIds);
    }
    return defaultExpandedCategoryIds(tree);
  });

  const [positionOverrides, setPositionOverrides] = useState<Record<string, { x: number; y: number }>>({});

  const baseLaid = useMemo(() => layoutTreeToFlow(tree, expanded), [tree, expanded]);

  const laid = useMemo(() => {
    const over = positionOverrides;
    return {
      nodes: baseLaid.nodes.map((n) =>
        over[n.id] ? { ...n, position: { ...over[n.id] } } : n
      ),
      edges: baseLaid.edges,
    };
  }, [baseLaid, positionOverrides]);

  const reparentUrl = categoryReparentUrl?.trim() || '';
  const csrf = csrfToken?.trim() || '';

  const categoryPickerRows = useMemo(() => listCategoriesForPicker(tree), [tree]);

  const onJumpToCategory = useCallback(
    (flowNodeId: string) => {
      const needed = categoryIdsRequiredExpandedForTarget(tree, flowNodeId);
      if (needed === null) return;
      setExpanded((prev) => {
        const next = new Set(prev);
        needed.forEach((id) => next.add(id));
        return next;
      });
      setPendingFocusNodeId(flowNodeId);
    },
    [tree]
  );

  const emit = useCallback(
    (nodeId: string, action: NodeAction) => {
      const node = findNode(tree, nodeId);
      if (!node) return;
      onAction?.({ nodeId, action, node });

      if (action === 'toggle-expand' && node.type === 'category') {
        setExpanded((prev) => {
          const next = new Set(prev);
          if (next.has(nodeId)) next.delete(nodeId);
          else next.add(nodeId);
          return next;
        });
      }
    },
    [onAction, tree]
  );

  return (
    <ActionsProvider value={{ emit }}>
      <div
        ref={mapContainerRef}
        className={`flex min-h-0 w-full flex-col bg-white ${isFullscreen ? 'h-screen max-h-[100dvh]' : 'h-full'}`}
      >
        <CategoryJumpToolbar
          rows={categoryPickerRows}
          onPick={onJumpToCategory}
          fullscreenSupported={fullscreenSupported}
          isFullscreen={isFullscreen}
          onToggleFullscreen={onToggleFullscreen}
        />
        <div className="min-h-0 flex-1">
          <ReactFlowProvider>
            <FlowBody
              laid={laid}
              tree={tree}
              onAction={onAction}
              categoryReparentUrl={reparentUrl}
              csrfToken={csrf}
              setPositionOverrides={setPositionOverrides}
              pendingFocusNodeId={pendingFocusNodeId}
              onFocusApplied={clearPendingFocus}
              mapContainerRef={mapContainerRef}
            />
          </ReactFlowProvider>
        </div>
      </div>
    </ActionsProvider>
  );
}

export function ServicesMapCanvas(props: ServicesMapMountOptions) {
  const { tree, initiallyExpandedIds, onAction, height, categoryReparentUrl, csrfToken } = props;
  const h = height ?? '70vh';
  return (
    <div className="flex h-full min-h-0 w-full flex-col" style={{ height: h, minHeight: h }}>
      <InnerCanvas
        tree={tree}
        initiallyExpandedIds={initiallyExpandedIds}
        onAction={onAction}
        categoryReparentUrl={categoryReparentUrl}
        csrfToken={csrfToken}
      />
    </div>
  );
}
