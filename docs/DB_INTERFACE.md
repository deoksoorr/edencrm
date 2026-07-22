# EDEN CRM — DB 인터페이스 기준 (T2 확정 스키마)

다운스트림 컨트롤러/모델은 아래 테이블·컬럼명을 **그대로** 사용한다. 새 컬럼 추정 금지.

## perm_key (30개) — Rbac::require 에 사용
- audit.view (audit)
- contract.manage (contract)
- contract.view (contract)
- cost.manage (cost)
- customer.delete (customer)
- customer.export (customer)
- customer.manage (customer)
- customer.view (customer)
- finance.view (finance)
- payment.manage (finance)
- performance.view_all (performance)
- pipeline.manage (pipeline)
- pipeline.view (pipeline)
- process.move (process)
- project.assign (project)
- project.manage (project)
- project.view_all (project)
- project.view_assigned (project)
- quote.manage (quote)
- quote.view (quote)
- report.export (report)
- report.view (report)
- schedule.manage (schedule)
- schedule.view_all (schedule)
- settings.manage (settings)
- staff.manage (staff)
- staff.view (staff)
- worklog.confirm (worklog)
- worklog.create (worklog)
- worklog.view_all (worklog)

## role_key: super_admin, sales_manager, site_manager, staff, accountant

## pipeline_stages (stage_key)
- 1 new_inquiry — 신규문의
- 2 consult_booked — 상담예약
- 3 site_survey — 현장실측
- 4 quote_drafting — 견적작성
- 5 quote_sent — 견적발송
- 6 negotiating — 협상중
- 7 contract_pending — 계약대기
- 8 contract_won — 계약완료 [WON]
- 9 on_hold — 보류
- 10 no_response — 장기미응답
- 11 lost — 실주 [LOST]
- 12 cancelled — 취소 [LOST]

## process_stages (stage_key)
- 1 site_survey — 현장실측
- 2 drawing — 도면작성
- 3 material_order — 자재발주
- 4 prep — 착공준비
- 5 protection — 양생/보양
- 6 pressure_wash — 고압세척
- 7 surface_prep — 바탕처리(면처리)
- 8 crack_repair — 크랙보수 [confirm]
- 9 putty — 퍼티/퍼팅
- 10 waterproofing — 방수처리 [confirm]
- 11 primer — 프라이머
- 12 paint_1st — 1차도장
- 13 paint_2nd — 2차도장
- 14 paint_3rd — 3차도장(마감) [confirm]
- 15 drying — 건조양생
- 16 site_cleanup — 현장정리
- 17 final_inspection — 준공검사 [confirm]
- 18 warranty_repair — 하자보수 [confirm]

## 전체 테이블 컬럼 (name type nullable key)

### audit_logs
- id int unsigned NOT NULL [PRI]
- user_id int unsigned [MUL]
- action varchar(50) NOT NULL
- entity varchar(50) NOT NULL [MUL]
- entity_id int unsigned
- before_json text
- after_json text
- ip varchar(45)
- user_agent varchar(255)
- created_at datetime NOT NULL [MUL]

### contracts
- id int unsigned NOT NULL [PRI]
- contract_no varchar(30) NOT NULL [UNI]
- quote_id int unsigned [MUL]
- customer_id int unsigned NOT NULL [MUL]
- contract_date date
- contract_amount decimal(14,0) NOT NULL
- down_payment decimal(14,0) NOT NULL
- middle_payment decimal(14,0) NOT NULL
- balance_payment decimal(14,0) NOT NULL
- start_date date
- end_date date
- warranty_period varchar(20)
- status varchar(20) NOT NULL [MUL]
- payment_status varchar(20) NOT NULL
- contract_file_id int unsigned
- special_terms text
- sales_user_id int unsigned [MUL]
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### costs
- id int unsigned NOT NULL [PRI]
- project_id int unsigned NOT NULL [MUL]
- type enum('estimate','actual') NOT NULL [MUL]
- category varchar(30) NOT NULL [MUL]
- amount decimal(14,0) NOT NULL
- spent_date date [MUL]
- memo varchar(255)
- created_by int unsigned [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### customer_activities
- id int unsigned NOT NULL [PRI]
- customer_id int unsigned NOT NULL [MUL]
- user_id int unsigned NOT NULL [MUL]
- activity_type varchar(20) NOT NULL [MUL]
- content text
- activity_at datetime NOT NULL [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### customer_contacts
- id int unsigned NOT NULL [PRI]
- customer_id int unsigned NOT NULL [MUL]
- name varchar(50) NOT NULL
- position varchar(50)
- phone varchar(20)
- email varchar(100)
- is_primary tinyint(1) NOT NULL
- memo varchar(255)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### customers
- id int unsigned NOT NULL [PRI]
- type enum('individual','company') NOT NULL
- name varchar(100) NOT NULL
- company_name varchar(100)
- contact_name varchar(50)
- phone varchar(20) [MUL]
- email varchar(100) [MUL]
- address varchar(255)
- site_address varchar(255)
- source varchar(50)
- interest_type varchar(50)
- expected_scale varchar(50)
- expected_budget decimal(14,0)
- desired_consult_date date
- sales_user_id int unsigned [MUL]
- status varchar(20) NOT NULL [MUL]
- tags varchar(255)
- privacy_agreed tinyint(1) NOT NULL
- memo text
- last_consult_date date
- next_contact_date date [MUL]
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### departments
- id int unsigned NOT NULL [PRI]
- name varchar(100) NOT NULL [UNI]
- description varchar(255)
- sort_order int unsigned NOT NULL
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### holidays
- id int unsigned NOT NULL [PRI]
- holiday_date date NOT NULL [UNI]
- name varchar(100) NOT NULL
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### leads
- id int unsigned NOT NULL [PRI]
- customer_id int unsigned NOT NULL [MUL]
- sales_user_id int unsigned [MUL]
- stage_id int unsigned NOT NULL [MUL]
- work_type varchar(50)
- site_address varchar(255)
- expected_amount decimal(14,0)
- expected_cost decimal(14,0)
- win_probability decimal(5,2)
- expected_profit decimal(14,0)
- importance varchar(10)
- next_contact_date date [MUL]
- last_activity_date date
- stage_entered_at datetime
- tags varchar(255)
- memo text
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### login_attempts
- id int unsigned NOT NULL [PRI]
- login_id varchar(50) NOT NULL [MUL]
- ip varchar(45) [MUL]
- success tinyint(1) NOT NULL
- created_at datetime NOT NULL [MUL]

### notifications
- id int unsigned NOT NULL [PRI]
- user_id int unsigned NOT NULL [MUL]
- type varchar(30) NOT NULL
- title varchar(150) NOT NULL
- message varchar(500)
- link_route varchar(100)
- link_params varchar(255)
- is_read tinyint(1) NOT NULL [MUL]
- read_at datetime
- created_at datetime NOT NULL [MUL]

### payments
- id int unsigned NOT NULL [PRI]
- contract_id int unsigned NOT NULL [MUL]
- pay_type varchar(20) NOT NULL
- amount decimal(14,0) NOT NULL
- due_date date [MUL]
- paid_date date
- status varchar(20) NOT NULL [MUL]
- memo varchar(255)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### permissions
- id int unsigned NOT NULL [PRI]
- perm_key varchar(50) NOT NULL [UNI]
- name varchar(100) NOT NULL
- category varchar(50)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### pipeline_stages
- id int unsigned NOT NULL [PRI]
- stage_key varchar(30) NOT NULL [UNI]
- name varchar(50) NOT NULL
- sort_order int unsigned NOT NULL [MUL]
- is_won tinyint(1) NOT NULL
- is_lost tinyint(1) NOT NULL
- color varchar(20)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### process_stages
- id int unsigned NOT NULL [PRI]
- stage_key varchar(30) NOT NULL [UNI]
- name varchar(50) NOT NULL
- sort_order int unsigned NOT NULL [MUL]
- requires_confirm tinyint(1) NOT NULL
- color varchar(20)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### project_assignments
- id int unsigned NOT NULL [PRI]
- project_id int unsigned NOT NULL [MUL]
- user_id int unsigned NOT NULL [MUL]
- role varchar(20) NOT NULL
- start_date date
- end_date date
- planned_hours decimal(6,1)
- actual_hours decimal(6,1)
- contribution_pct decimal(5,2) NOT NULL
- status varchar(20) NOT NULL [MUL]
- memo varchar(255)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### project_files
- id int unsigned NOT NULL [PRI]
- project_id int unsigned [MUL]
- entity_type varchar(30) [MUL]
- entity_id int unsigned
- original_name varchar(255) NOT NULL
- stored_name varchar(255) NOT NULL
- path varchar(500) NOT NULL
- size int unsigned NOT NULL
- mime varchar(100)
- uploaded_by int unsigned [MUL]
- created_at datetime NOT NULL

### project_process_history
- id int unsigned NOT NULL [PRI]
- project_id int unsigned NOT NULL [MUL]
- from_stage_id int unsigned [MUL]
- to_stage_id int unsigned NOT NULL [MUL]
- changed_by int unsigned [MUL]
- reason varchar(255)
- changed_at datetime NOT NULL [MUL]

### projects
- id int unsigned NOT NULL [PRI]
- project_no varchar(30) NOT NULL [UNI]
- name varchar(150) NOT NULL
- customer_id int unsigned NOT NULL [MUL]
- contract_id int unsigned [MUL]
- site_address varchar(255)
- work_type varchar(50)
- contract_amount decimal(14,0) NOT NULL
- estimated_cost decimal(14,0) NOT NULL
- actual_cost decimal(14,0) NOT NULL
- process_stage_id int unsigned [MUL]
- status varchar(20) NOT NULL [MUL]
- contract_date date
- start_date date
- end_date date [MUL]
- actual_start_date date
- actual_end_date date
- sales_user_id int unsigned [MUL]
- site_manager_id int unsigned [MUL]
- progress tinyint unsigned NOT NULL
- importance varchar(10)
- contribution_mode varchar(10) NOT NULL
- memo text
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### quote_items
- id int unsigned NOT NULL [PRI]
- quote_version_id int unsigned NOT NULL [MUL]
- name varchar(100) NOT NULL
- area decimal(10,2)
- qty decimal(10,2) NOT NULL
- unit_price decimal(14,0) NOT NULL
- material_cost decimal(14,0) NOT NULL
- labor_cost decimal(14,0) NOT NULL
- equipment_cost decimal(14,0) NOT NULL
- outsourcing_cost decimal(14,0) NOT NULL
- etc_cost decimal(14,0) NOT NULL
- amount decimal(14,0) NOT NULL
- sort_order int unsigned NOT NULL
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### quote_versions
- id int unsigned NOT NULL [PRI]
- quote_id int unsigned NOT NULL [MUL]
- version_no int unsigned NOT NULL
- subtotal decimal(14,0) NOT NULL
- vat decimal(14,0) NOT NULL
- discount decimal(14,0) NOT NULL
- total_amount decimal(14,0) NOT NULL
- note text
- created_by int unsigned [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### quotes
- id int unsigned NOT NULL [PRI]
- quote_no varchar(30) NOT NULL [UNI]
- lead_id int unsigned [MUL]
- customer_id int unsigned NOT NULL [MUL]
- status varchar(20) NOT NULL [MUL]
- current_version_id int unsigned [MUL]
- valid_until date
- memo text
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### role_permissions
- id int unsigned NOT NULL [PRI]
- role_id int unsigned NOT NULL [MUL]
- permission_id int unsigned NOT NULL [MUL]
- created_at datetime NOT NULL

### roles
- id int unsigned NOT NULL [PRI]
- role_key varchar(30) NOT NULL [UNI]
- name varchar(50) NOT NULL
- description varchar(255)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### schedules
- id int unsigned NOT NULL [PRI]
- project_id int unsigned [MUL]
- user_id int unsigned NOT NULL [MUL]
- title varchar(150) NOT NULL
- start_datetime datetime NOT NULL [MUL]
- end_datetime datetime NOT NULL [MUL]
- all_day tinyint(1) NOT NULL
- type varchar(20) NOT NULL
- color varchar(20)
- status varchar(20) NOT NULL
- memo text
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### settings
- id int unsigned NOT NULL [PRI]
- setting_key varchar(50) NOT NULL [UNI]
- value varchar(255)
- group varchar(50)
- label varchar(100)
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### targets
- id int unsigned NOT NULL [PRI]
- user_id int unsigned NOT NULL [MUL]
- year smallint unsigned NOT NULL [MUL]
- month tinyint unsigned NOT NULL
- target_revenue decimal(14,0) NOT NULL
- target_profit decimal(14,0) NOT NULL
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### user_permissions
- id int unsigned NOT NULL [PRI]
- user_id int unsigned NOT NULL [MUL]
- permission_id int unsigned NOT NULL [MUL]
- is_grant tinyint(1) NOT NULL
- created_at datetime NOT NULL

### users
- id int unsigned NOT NULL [PRI]
- login_id varchar(50) NOT NULL [UNI]
- email varchar(100) NOT NULL [UNI]
- password_hash varchar(255) NOT NULL
- name varchar(50) NOT NULL
- phone varchar(20)
- department_id int unsigned [MUL]
- position varchar(50)
- role_id int unsigned NOT NULL [MUL]
- role_key varchar(30) NOT NULL
- hire_date date
- status enum('active','inactive') NOT NULL [MUL]
- must_change_password tinyint(1) NOT NULL
- target_revenue decimal(14,0)
- target_profit decimal(14,0)
- profile_image varchar(255)
- memo text
- last_login_at datetime
- last_login_ip varchar(45)
- failed_attempts int unsigned NOT NULL
- locked_until datetime
- deleted_at datetime [MUL]
- created_at datetime NOT NULL
- updated_at datetime NOT NULL

### work_log_photos
- id int unsigned NOT NULL [PRI]
- work_log_id int unsigned NOT NULL [MUL]
- file_id int unsigned NOT NULL [MUL]
- created_at datetime NOT NULL

### work_logs
- id int unsigned NOT NULL [PRI]
- project_id int unsigned NOT NULL [MUL]
- user_id int unsigned NOT NULL [MUL]
- work_date date NOT NULL [MUL]
- start_time time
- end_time time
- process_stage_id int unsigned [MUL]
- content text
- materials varchar(255)
- material_qty varchar(100)
- equipment varchar(255)
- weather varchar(20)
- progress tinyint unsigned
- issues text
- delay_reason varchar(255)
- next_work varchar(255)
- confirmed_by int unsigned [MUL]
- confirmed_at datetime
- created_at datetime NOT NULL
- updated_at datetime NOT NULL
