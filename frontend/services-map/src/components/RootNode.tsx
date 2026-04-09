import { Handle, Position, type NodeProps } from '@xyflow/react';
import { memo } from 'react';
import type { OlliraTreeNode } from '../types';
import { ActionStrip } from './NodeChrome';

export type SharedRfData = {
  treeNode: OlliraTreeNode;
  parentId: string | null;
  isCollapsed: boolean;
  hasChildren: boolean;
};

function RootNodeImpl(props: NodeProps) {
  const { treeNode, hasChildren, isCollapsed } = props.data as SharedRfData;

  return (
    <div className="rounded-xl border border-slate-200 bg-gradient-to-br from-slate-900 to-slate-800 px-4 py-3 text-white shadow-lg ring-1 ring-white/10">
      <div className="text-center text-sm font-bold tracking-wide">{treeNode.name}</div>
      <ActionStrip
        nodeId={treeNode.id}
        parentId={null}
        kind="root"
        hasChildren={hasChildren}
        showExpand={false}
        isCollapsed={isCollapsed}
      />
      <Handle type="source" position={Position.Right} className="!h-3 !w-3 !border-2 !border-white !bg-slate-900" />
    </div>
  );
}

export const RootNode = memo(RootNodeImpl);
