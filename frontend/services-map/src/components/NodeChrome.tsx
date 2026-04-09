import type { NodeAction } from '../types';
import { useMapActions } from '../context/actionsContext';

const btnBase =
  'inline-flex h-8 w-8 items-center justify-center rounded-lg border border-slate-200/80 bg-white text-slate-600 shadow-sm transition hover:border-slate-300 hover:bg-slate-50 hover:text-slate-900 active:scale-95';

export function ActionStrip({
  nodeId,
  parentId,
  kind,
  hasChildren,
  showExpand,
  isCollapsed,
}: {
  nodeId: string;
  parentId: string | null;
  kind: 'root' | 'category' | 'service';
  hasChildren: boolean;
  showExpand: boolean;
  isCollapsed: boolean;
}) {
  const { emit } = useMapActions();

  const addTarget = kind === 'service' && parentId ? parentId : nodeId;

  return (
    <div className="mt-2 flex flex-wrap items-center gap-1 border-t border-slate-100 pt-2">
      {showExpand && (
        <button
          type="button"
          className={btnBase}
          title={isCollapsed ? 'Expand' : 'Collapse'}
          onClick={() => emit(nodeId, 'toggle-expand')}
        >
          <span className="text-xs font-bold">{isCollapsed ? '▶' : '▼'}</span>
        </button>
      )}
      <button
        type="button"
        className={btnBase}
        title="Add"
        onClick={() => emit(addTarget, 'add-child')}
      >
        +
      </button>
      {kind !== 'root' && (
        <button
          type="button"
          className={btnBase}
          title="Edit"
          onClick={() => emit(nodeId, 'edit')}
        >
          ✎
        </button>
      )}
      {kind !== 'root' && (
        <button
          type="button"
          className={`${btnBase} hover:border-rose-200 hover:bg-rose-50 hover:text-rose-700`}
          title="Delete"
          onClick={() => emit(nodeId, 'delete')}
        >
          ✕
        </button>
      )}
    </div>
  );
}
