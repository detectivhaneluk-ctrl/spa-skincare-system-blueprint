# Ollira Services Map (React Flow + Dagre)

Interactive **left-to-right** hierarchical map for Services → Structure view.

## Commands

```bash
cd frontend/services-map
npm install
npm run build    # outputs to system/public/assets/services-map/
npm run dev      # Vite dev server + Armenian mock tree
```

## Architecture

| Piece | Role |
|-------|------|
| `src/types.ts` | `OlliraTreeNode` JSON shape (`root` \| `category` \| `service`) |
| `src/lib/treeToFlow.ts` | Flattens tree by expand-state → runs **@dagrejs/dagre** (`rankdir: LR`) → XYFlow nodes/edges |
| `src/lib/treeUtils.ts` | `findNode`, default expanded category IDs |
| `src/components/*Node.tsx` | Custom node UI (Tailwind): root, category cards, green service cards |
| `src/components/NodeChrome.tsx` | Shared action strip (+ / edit / delete / expand) |
| `src/context/actionsContext.tsx` | Dispatches `emit(nodeId, action)` into nodes |
| `src/ServicesMapCanvas.tsx` | `ReactFlowProvider`, layout sync, `fitView` on expand/collapse |
| `src/embed.tsx` | IIFE entry → `window.OlliraServicesMap.mount(el, options)` |
| `src/main-dev.tsx` | Dev shell importing `mock/salonTree.hy.json` |
| `system/.../_flow_tree_build.php` | PHP → same JSON tree for production |

## PHP integration

`services/index.php` loads `/assets/services-map/ollira-services-map.{js,css}` when the bundle exists and passes JSON from `ollira_services_build_flow_tree(...)`.
