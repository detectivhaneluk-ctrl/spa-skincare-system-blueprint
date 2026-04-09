import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import './index.css';
import { ServicesMapCanvas } from './ServicesMapCanvas';
import mockTree from './mock/salonTree.mock.json';
import type { OlliraTreeNode } from './types';

const rootEl = document.getElementById('root');
if (rootEl) {
  createRoot(rootEl).render(
    <StrictMode>
      <div className="h-full w-full p-3">
        <h1 className="mb-2 px-1 text-sm font-semibold text-slate-600">
          Ollira — categories and services (dev, mock JSON)
        </h1>
        <div className="h-[calc(100%-2rem)] min-h-[520px] rounded-xl border border-slate-200 bg-slate-100 shadow-inner">
          <ServicesMapCanvas
            tree={mockTree as OlliraTreeNode}
            height="100%"
            onAction={(p) => {
              console.log('[OlliraServicesMap]', p.action, p.nodeId, p.node.name);
            }}
          />
        </div>
      </div>
    </StrictMode>
  );
}
