# CRM OMD Time Manager (WordPress plugin)

MVP+ wtyczki do:
- rejestracji czasu pracy pracowników,
- akceptacji, edycji i usuwania wpisów przez administratora,
- wprowadzania wpisów przez administratora w imieniu pracownika,
- duplikowania wpisów ryczałtowych,
- filtrowania i sortowania wpisów (data, status, pracownik, klient),
- raportów z zakresem dat (domyślnie per miesiąc),
- wyliczania wartości wpisów na podstawie stawek klienta,
- eksportu raportu do CSV (Excel-friendly),
- konfigurowalnych przypomnień mailowych (co X dni lub konkretnego dnia miesiąca),
- osobnej zakładki do zarządzania pracownikami (aktywność, przypomnienia, rola, hasło, usunięcie konta),
- zapisu daty ostatniego logowania pracownika,
- panelu logowania pracownika z miejscem na branding/logo (`[crm_omd_employee_login]`),
- zintegrowanego panelu pracownika (`[crm_omd_employee_portal]`) ze statystykami i raportowaniem godzin na jednej podstronie,
- miesięcznego podglądu godzin pracownika (`[crm_omd_employee_monthly_view]`) z tabelą wpisów, sumą zaraportowanych godzin oraz wymiarem godzin roboczych (8h/dzień).

## Instalacja
1. Skopiuj plik `crm-omd-time-manager.php` do katalogu pluginu (np. `wp-content/plugins/crm-omd-time-manager/`).
2. Aktywuj wtyczkę w panelu WordPress.
3. Dodaj stronę z shortcode:
   - `[crm_omd_time_tracker]` (formularz godzin),
   - `[crm_omd_employee_login logo_url="https://example.com/logo.png" redirect_to="https://twoja-domena.pl/panel-pracownika/"]` (panel logowania z brandingiem i przekierowaniem),
   - `[crm_omd_employee_portal]` (pełny panel pracownika: statystyki + raportowanie godzin),
   - `[crm_omd_employee_monthly_view]` (sam widok podsumowania miesiąca, opcjonalnie `month="YYYY-MM"`).
4. W panelu admina skonfiguruj klientów, projekty, usługi oraz pracowników.

## Uwaga
- Wtyczka tworzy własne tabele w bazie danych przy aktywacji.
- Do klienta dostępne są pola: NIP, osoba kontaktowa, email kontaktowy.
- Eksport jest w formacie CSV z separatorem `;`.


## Rekomendowany flow
- Strona logowania pracownika: shortcode `[crm_omd_employee_login]`.
- Strona docelowa po zalogowaniu: shortcode `[crm_omd_employee_portal]` (bez potrzeby przełączania podstron).
