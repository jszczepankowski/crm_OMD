# CRM OMD Time Manager (WordPress plugin)

MVP+ wtyczki do:
- rejestracji czasu pracy pracowników,
- akceptacji, edycji i usuwania wpisów przez administratora,
- filtrowania i sortowania wpisów (data, status, pracownik, klient),
- raportów z zakresem dat (domyślnie per miesiąc),
- wyliczania wartości wpisów na podstawie stawek klienta,
- eksportu raportu do CSV (Excel-friendly),
- konfigurowalnych przypomnień mailowych (co X dni lub konkretnego dnia miesiąca),
- osobnej zakładki do zarządzania pracownikami (aktywność, przypomnienia, rola, hasło, usunięcie konta),
- zapisu daty ostatniego logowania pracownika,
- panelu logowania pracownika z miejscem na branding/logo (`[crm_omd_employee_login]`).

## Instalacja
1. Skopiuj plik `crm-omd-time-manager.php` do katalogu pluginu (np. `wp-content/plugins/crm-omd-time-manager/`).
2. Aktywuj wtyczkę w panelu WordPress.
3. Dodaj stronę z shortcode:
   - `[crm_omd_time_tracker]` (formularz godzin),
   - `[crm_omd_employee_login logo_url="https://example.com/logo.png"]` (panel logowania z brandingiem).
4. W panelu admina skonfiguruj klientów, projekty, usługi oraz pracowników.

## Uwaga
- Wtyczka tworzy własne tabele w bazie danych przy aktywacji.
- Do klienta dostępne są pola: NIP, osoba kontaktowa, email kontaktowy.
- Eksport jest w formacie CSV z separatorem `;`.
