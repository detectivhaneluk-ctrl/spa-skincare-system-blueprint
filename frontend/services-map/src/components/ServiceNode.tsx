import { Handle, Position, type NodeProps } from '@xyflow/react';
import { memo, useMemo } from 'react';
import type { SharedRfData } from './RootNode';
import { ActionStrip } from './NodeChrome';

function formatDuration(min: number | undefined) {
  if (min == null || Number.isNaN(min)) return '—';
  if (min < 60) return `${min} min`;
  const h = Math.floor(min / 60);
  const r = min % 60;
  return r ? `${h} h ${r} min` : `${h} h`;
}

function formatPrice(price: number | undefined, currency: string | undefined) {
  if (price == null) return '—';
  const cur = currency ?? 'AMD';
  return `${price.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ${cur}`;
}

function ServiceNodeImpl(props: NodeProps) {
  const { treeNode, parentId, hasChildren, isCollapsed } = props.data as SharedRfData;
  const dur = useMemo(
    () => formatDuration(treeNode.durationMinutes),
    [treeNode.durationMinutes]
  );
  const pr = useMemo(
    () => formatPrice(treeNode.price, treeNode.currency),
    [treeNode.price, treeNode.currency]
  );

  return (
    <div className="w-[276px] rounded-xl border border-emerald-700/25 bg-gradient-to-br from-emerald-600 to-teal-700 px-3 py-2.5 text-white shadow-lg shadow-emerald-900/25 ring-1 ring-white/20">
      <Handle
        type="target"
        position={Position.Left}
        className="!h-3 !w-3 !border-2 !border-white/70 !bg-emerald-700"
      />
      <div className="line-clamp-2 min-h-[2.5rem] text-sm font-semibold leading-snug">{treeNode.name}</div>
      <div className="mt-1.5 flex flex-wrap gap-x-3 gap-y-0.5 text-xs font-medium text-emerald-50/95">
        <span>{dur}</span>
        <span className="rounded-md bg-white/15 px-2 py-0.5">{pr}</span>
      </div>
      <ActionStrip
        nodeId={treeNode.id}
        parentId={parentId}
        kind="service"
        hasChildren={hasChildren}
        showExpand={false}
        isCollapsed={isCollapsed}
      />
    </div>
  );
}

export const ServiceNode = memo(ServiceNodeImpl);
