# Telco SQL Sync

[English](README.md) | **Shqip**

Një plugin për WooCommerce që importon produkte nga një skedar `.sql` lokal (dump).

SQL **analizohet (parse), nuk ekzekutohet kurrë** – lexohen vetëm komandat `INSERT` dhe shndërrohen në produkte WooCommerce.

## Veçoritë
- Ngarkoni një skedar `.sql`, ose tregoni rrugën drejt një skedari në server (e përshtatshme për dump-e të mëdha)
- Zbulon automatikisht tabelat dhe kolonat, me një ekran përputhjeje (mapping) për të korrigjuar çdo hamendësim të gabuar
- Përputh emrin, SKU-në, çmimet, përshkrimet, stokun, statusin, kategoritë (hierarki `A > B`), etiketat, imazhet, peshën dhe përmasat
- Krijon produkte të reja ose përditëson/kapërcen ato ekzistueset (të përputhura sipas SKU-së ose një ID burimi)
- Importon imazhe nga URL ose nga një dosje në server në bibliotekën e medias
- Import me grupe (batch) përmes AJAX, me shirit progresi, regjistër gabimesh dhe vazhdim pas ndërprerjes

## Instalimi
Kopjojeni këtë dosje te `wp-content/plugins/telco-sql-sync/` dhe aktivizojeni (kërkohet WooCommerce). Pastaj shkoni te **Products → Telco SQL Sync**.

Provojeni me `sample/sample-products.sql`.

## Kufizime
- Vetëm produkte të thjeshta (ende pa produkte me variante)
- ID-të e çelësave të huaj (p.sh. një kategori e ruajtur si `3`) nuk zgjidhen
- Komandat INSERT duhet të kenë emrat e kolonave, ose skedari duhet të ketë një `CREATE TABLE`

## Licenca
GPL-2.0-or-later. Shihni [LICENSE](LICENSE) (teksti zyrtar anglisht) dhe [LICENSE.sq.md](LICENSE.sq.md) (përmbledhje në shqip).
