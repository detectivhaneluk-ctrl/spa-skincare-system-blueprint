# Cursor նախագծային կանոններ

> **ARCHIVAL — NOT AUTHORITATIVE**  
> Historical Cursor-oriented rules. Prefer `system/docs/MAINTAINER-RUNTIME-TRUTH.md` and live code under `system/`.

- Օգտագործիր միայն այս փաստաթղթավորված մոդուլային կառուցվածքը։
- Նոր ֆայլ ստեղծելուց առաջ որոշիր՝ դա միջուկի՞, shared-ի՞, թե կոնկրետ մոդուլի ներսում պիտի ապրի։
- Չկրկնես նույն UI բաղադրիչը տարբեր մոդուլներում։
- Գործողությունների պատմությունը բաց մի թող որևէ կարևոր create/update/delete/approve/cancel/refund գործողության դեպքում։
- Բոլոր կարգավիճակների փոփոխությունները պահիր հստակ transition կանոններով։
- Այն ամենը, ինչ վաղը կարող է փոխվել owner-ի կողմից, փորձիր դարձնել կարգավորումներով կառավարվող։
