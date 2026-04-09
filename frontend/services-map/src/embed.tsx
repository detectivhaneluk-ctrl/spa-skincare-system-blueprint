import { StrictMode } from 'react';
import { createRoot, type Root } from 'react-dom/client';
import './index.css';
import { ServicesMapCanvas } from './ServicesMapCanvas';
import type { ServicesMapMountOptions } from './types';

let mountedRoot: Root | null = null;

export function mount(el: HTMLElement, options: ServicesMapMountOptions) {
  if (!el) return;
  unmount();
  const h = options.height ?? '72vh';
  el.style.minHeight = h;
  el.style.height = h;
  el.style.width = '100%';
  el.style.boxSizing = 'border-box';
  mountedRoot = createRoot(el);
  mountedRoot.render(
    <StrictMode>
      <div className="ollira-flow-host h-full w-full min-h-[420px] rounded-xl border border-slate-200 bg-slate-100 shadow-inner">
        <ServicesMapCanvas {...options} />
      </div>
    </StrictMode>
  );
}

export function unmount() {
  mountedRoot?.unmount();
  mountedRoot = null;
}

declare global {
  interface Window {
    OlliraServicesMap?: {
      mount: typeof mount;
      unmount: typeof unmount;
    };
  }
}

window.OlliraServicesMap = { mount, unmount };
