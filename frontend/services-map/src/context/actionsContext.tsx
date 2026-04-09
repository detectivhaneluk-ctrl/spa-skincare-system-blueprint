import { createContext, useContext, type ReactNode } from 'react';
import type { NodeAction } from '../types';

type Ctx = {
  emit: (nodeId: string, action: NodeAction) => void;
};

const ActionsContext = createContext<Ctx | null>(null);

export function ActionsProvider({
  value,
  children,
}: {
  value: Ctx;
  children: ReactNode;
}) {
  return <ActionsContext.Provider value={value}>{children}</ActionsContext.Provider>;
}

export function useMapActions() {
  const c = useContext(ActionsContext);
  if (!c) throw new Error('useMapActions outside ActionsProvider');
  return c;
}
