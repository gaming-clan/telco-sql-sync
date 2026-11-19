# Telco SQL Sync

[English](README.md) | **Shqip**

Një plugin për WooCommerce që importon produkte nga një skedar `.sql` lokal (dump).

SQL **analizohet (parse), nuk ekzekutohet kurrë** – lexohen vetëm komandat `INSERT` dhe shndërrohen në produkte WooCommerce.

## Veçoritë
- Ngarkoni një skedar `.sql`, ose tregoni rrugën drejt një skedari në server (e përshtatshme për dump-e të mëdha)
- Zbulon automatikisht tabelat dhe kolonat, me një ekran përputhjeje (mapping) për të korrigjuar çdo hamendësim të gabuar
- Përputh emrin, tipin, SKU-në, çmimet (kupton `1,299.00`, `1.299,50`, `12,5`), datat e ofertës, përshkrimet, stokun, backorder, statusin, dukshmërinë, kategoritë (hierarki `A > B`), etiketat, atributet, imazhet, statusin/klasën e taksës, klasën e dërgesës, peshën dhe përmasat, upsell, cross-sell dhe më shumë
- Tipet e produkteve: i thjeshtë, me variante (variantet si rreshta fëmijë përmes SKU-së së prindit), i grupuar dhe i jashtëm
- Opsionale: ruaj çdo kolonë të papërputhur si fushë e personalizuar (`sql_<kolona>`)
- Krijon produkte të reja ose përditëson/kapërcen ato ekzistueset (të përputhura sipas SKU-së ose një ID burimi)
- Importon imazhe në bibliotekën e medias: një kolonë me URL ose rrugë skedari bëhet foto kryesore e produktit, kurse një kolonë me rrugën e një dosjeje i vendos të gjitha imazhet e asaj dosjeje në galerinë e produktit
- Mund të ngarkoni opsionalisht një ZIP me imazhet, që rrugët dhe dosjet në SQL të gjenden brenda tij (ruhen vetëm skedarët imazh)
- Rrugët lokale të imazheve kufizohen te dosja që zgjidhni; asgjë jashtë saj nuk lexohet kurrë
- Import me grupe (batch) përmes AJAX, me shirit progresi, regjistër gabimesh dhe vazhdim pas ndërprerjes

## Instalimi
Kopjojeni këtë dosje te `wp-content/plugins/telco-sql-sync/` dhe aktivizojeni (kërkohet WooCommerce). Pastaj shkoni te **Products → Telco SQL Sync**.

Provojeni me `sample/sample-products.sql`.

## Kufizime
- Pa vlerësime (reviews) të produkteve, pa atribute globale (taksonomi), pa produkte me skedarë për shkarkim
- ID-të e çelësave të huaj (p.sh. një kategori e ruajtur si `3`) nuk zgjidhen
- Rreshtat e varianteve, produkteve të grupuara, upsell dhe cross-sell mund të referojnë vetëm produkte që shfaqen më herët në skedar
- Komandat INSERT duhet të kenë emrat e kolonave, ose skedari duhet të ketë një `CREATE TABLE`

## Licenca
GPL-2.0-or-later. Shihni [LICENSE](LICENSE) (teksti zyrtar anglisht) dhe [LICENSE.sq.md](LICENSE.sq.md) (përmbledhje në shqip).
