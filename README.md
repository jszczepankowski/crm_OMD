# CRM OMD Time Manager (WordPress plugin)

MVP+ wtyczki do:
- rejestracji czasu pracy pracowników,
- akceptacji, edycji i usuwania wpisów przez administratora,
- filtrowania i sortowania wpisów (data, status, pracownik, klient),
- raportów miesięcznych (ogólnych i szczegółowych),
- wyliczania wartości wpisów na podstawie stawek klienta,
- eksportu raportu do CSV (Excel-friendly),
- codziennego przypomnienia mailowego o uzupełnianiu godzin,
- osobnej zakładki do zarządzania pracownikami (aktywność i przypomnienia).

## Instalacja
1. Skopiuj plik `crm-omd-time-manager.php` do katalogu pluginu (np. `wp-content/plugins/crm-omd-time-manager/`).
2. Aktywuj wtyczkę w panelu WordPress.
3. Dodaj stronę z shortcode: `[crm_omd_time_tracker]`.
4. W panelu admina skonfiguruj klientów, projekty, usługi oraz pracowników.

## Uwaga
- Wtyczka tworzy własne tabele w bazie danych przy aktywacji.
- Eksport jest w formacie CSV z separatorem `;`.
