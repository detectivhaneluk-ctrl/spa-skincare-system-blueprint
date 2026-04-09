/** Cross-browser helpers for the Fullscreen API (Safari webkit* variants). */

type DocFs = Document & {
  webkitFullscreenElement?: Element | null;
  webkitFullscreenEnabled?: boolean;
  webkitExitFullscreen?: () => Promise<void>;
};

type ElFs = HTMLElement & {
  webkitRequestFullscreen?: () => Promise<void>;
};

export function getFullscreenElement(): Element | null {
  const d = document as DocFs;
  return document.fullscreenElement ?? d.webkitFullscreenElement ?? null;
}

export function isFullscreenAvailable(): boolean {
  const d = document as DocFs;
  return !!(document.fullscreenEnabled ?? d.webkitFullscreenEnabled);
}

export function isElementInFullscreen(el: Element | null): boolean {
  return !!el && getFullscreenElement() === el;
}

export async function exitDocumentFullscreen(): Promise<void> {
  const d = document as DocFs;
  if (!getFullscreenElement()) return;
  if (document.exitFullscreen) {
    await document.exitFullscreen();
    return;
  }
  if (d.webkitExitFullscreen) {
    await d.webkitExitFullscreen();
  }
}

export async function requestElementFullscreen(el: HTMLElement): Promise<void> {
  const e = el as ElFs;
  if (e.requestFullscreen) {
    await e.requestFullscreen();
    return;
  }
  if (e.webkitRequestFullscreen) {
    await e.webkitRequestFullscreen();
  }
}

/** Enter fullscreen on `el`, or exit if `el` is already the fullscreen element. */
export async function toggleElementFullscreen(el: HTMLElement): Promise<void> {
  if (isElementInFullscreen(el)) {
    await exitDocumentFullscreen();
    return;
  }
  await requestElementFullscreen(el);
}
