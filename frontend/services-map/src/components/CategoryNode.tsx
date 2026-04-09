import { Handle, Position, type NodeProps } from '@xyflow/react';
import { memo } from 'react';
import type { SharedRfData } from './RootNode';
import { ActionStrip } from './NodeChrome';

function CategoryNodeImpl(props: NodeProps) {
  const { treeNode, hasChildren, isCollapsed } = props.data as SharedRfData;

  return (
    <div className="w-[252px] rounded-xl border border-slate-200/90 bg-white px-3 py-2.5 shadow-md shadow-slate-200/60 ring-1 ring-slate-100">
      <Handle
        type="target"
        position={Position.Left}
        className="!h-3 !w-3 !border-2 !border-slate-300 !bg-white"
      />
      <div className="line-clamp-2 min-h-[2.5rem] text-sm font-semibold leading-snug text-slate-800">
        {treeNode.name}
      </div>
      <ActionStrip
        nodeId={treeNode.id}
        parentId={treeNode.id}
        kind="category"
        hasChildren={hasChildren}
        showExpand={hasChildren}
        isCollapsed={isCollapsed}
      />
      <Handle
        type="source"
        position={Position.Right}
        className="!h-3 !w-3 !border-2 !border-slate-300 !bg-white"
      />
    </div>
  );
}

export const CategoryNode = memo(CategoryNodeImpl);
