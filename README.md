# EDEN CRM

> 도장 시공회사의 고객 유입부터 상담, 견적, 계약, 공정, 정산, 직원 성과까지 하나의 시스템에서 관리하는 내부 CRM 및 프로젝트 운영 시스템

<img width="1970" height="1202" alt="image" src="https://github.com/user-attachments/assets/2a3d8e8a-5803-41ae-ab73-1e38666d0362" />


EDEN CRM은 도장 및 인테리어 시공업체의 실제 업무 흐름을 웹 기반으로 통합 관리하기 위해 구축한 사내 업무 시스템입니다.

단순 고객관리 CRM이 아니라 영업 파이프라인, 견적, 계약, 프로젝트, 공정, 일정, 원가, 수익, 직원 성과, 회계 기준까지 하나의 데이터 흐름으로 연결하도록 설계했습니다.

기존 회사 홈페이지와는 완전히 분리된 독립 프로젝트이며, 일반 PHP 호스팅 환경에서도 운영할 수 있도록 경량 구조로 구현했습니다.

---

## Overview

- **Backend**: PHP 8.2+
- **Database**: MySQL 8 / MariaDB
- **Frontend**: HTML5, CSS3, Vanilla JavaScript
- **Charts**: Chart.js
- **Drag & Drop**: SortableJS
- **Architecture**: Lightweight Custom MVC
- **Hosting**: Standard PHP Hosting Compatible
- **Database Access**: PDO
- **Authentication**: Session-based Authentication
- **Development**: AI-assisted Development Workflow

---

## Key Features

### Customer CRM

- 고객 등록 및 상세 관리
- 고객 활동 타임라인
- 견적, 계약, 프로젝트 통합 조회
- 중복 고객 검사 및 병합
- CSV 내보내기

### Sales Pipeline

- Kanban 기반 영업 단계 관리
- Drag & Drop 단계 이동
- 예상 순이익률 자동 계산
- 가중 예상 매출 관리
- 영업 단계 자동 매핑

### Estimates & Contracts

- 견적 항목 및 버전 이력 관리
- 견적 인쇄
- 계약 등록 및 관리
- 입금 및 미수금 관리
- 계약 완료 후 프로젝트 자동 전환
- 계약 파기, 환불, 위약금, 정산 기록 관리

### Project Management

- 프로젝트 상태 관리
- 공정 단계 관리
- 현장 파일 및 사진 관리
- 프로젝트 상태 변경 이력
- 완료 및 정산 상태 관리
- 하자보수 상태 지원

### Process Board

- 공정 단계 Drag & Drop
- 공정 이력 자동 기록
- 지연 상태 표시
- 완료 단계 잠금
- 공정과 프로젝트 상태 분리 관리

### Schedule Management

- 월간 Calendar
- 직원별 주간 Timeline
- 일정 Drag & Drop
- 일정 충돌 감지
- 승인 기반 일정 변경

### Employee Management

- 프로젝트 다중 직원 배정
- 직원별 기여도 설정
- 작업일지
- 현장 사진
- 관리자 확인
- 직원별 성과 및 수익 기여도 분석

### Cost & Profitability

- 자재비 관리
- 인건비 관리
- 원가 자동 계산
- 증빙 첨부
- 원가 확정 프로세스
- 프로젝트별 실제 순이익 계산
- 적자 프로젝트 및 목표 달성률 분석

### Dashboard & Reports

- 권한별 Dashboard
- Chart.js 기반 주요 지표 시각화
- 기간별 실적 조회
- 직원 성과 분석
- 매출 및 수익 리포트
- CSV Export

### Notifications & Audit

- 업무 누락 방지 알림
- 중요 행동 Audit Log
- 관리자 변경 이력 추적

---

## Business Flow

```text
Customer Lead
    │
    ▼
Consultation
    │
    ▼
Estimate
    │
    ▼
Contract
    │
    ▼
Payment
    │
    ▼
Project
    │
    ▼
Process Management
    │
    ▼
Completion
    │
    ▼
Settlement
    │
    ▼
Revenue / Profit / Employee Performance
```

고객 유입 이후 발생하는 데이터를 서로 분리된 메뉴가 아니라 하나의 업무 흐름으로 연결하는 것을 핵심 목표로 설계했습니다.

---

## Architecture

```text
Browser
   │
   ▼
public/index.php
   │
   ▼
Router
   │
   ├── Authentication
   ├── RBAC
   ├── CSRF
   └── HTTP Method Validation
   │
   ▼
Controllers
   │
   ▼
Core Services
   │
   ├── AccountingService
   ├── StatusService
   ├── CostService
   ├── Scope
   ├── Audit
   └── Upload
   │
   ▼
MySQL
```

공통 계산 로직과 상태 변경 규칙은 Controller 또는 View에 분산시키지 않고 Core Service에서 관리하도록 구성했습니다.

---

## Project Structure

```text
public/
├── index.php
└── assets/

app/
├── config/
├── core/
├── controllers/
├── models/
├── views/
└── routes.php

storage/
├── uploads/
└── logs/

database/
├── schema.sql
├── seed_core.sql
└── seed_dev.sql

docs/
└── Architecture / Accounting / Process / QA / Deployment Docs

scripts/
├── qa_smoke.sh
├── security_probe.sh
└── tests/
```

---

## Role-Based Access Control

EDEN CRM은 단순 메뉴 숨김 방식이 아니라 요청 단계와 데이터 접근 단계 모두에서 권한을 검증합니다.

### Roles

```text
super_admin
sales_manager
site_manager
staff
accountant
```

총 5개 Role과 세분화된 Permission 구조를 사용합니다.

### Access Control

- Router 레벨 Permission 검증
- 로그인 상태 검증
- CSRF 검증
- HTTP Method 검증
- 사용자 역할별 메뉴 제어
- 담당 프로젝트 Scope 제한
- 배정 프로젝트 Scope 제한
- Query 레벨 데이터 접근 제한
- IDOR 방지

---

## Accounting Architecture

EDEN CRM의 매출, 원가, 순이익, 직원 기여도 및 목표 달성률 계산은 단일 Service에서 처리합니다.

```text
AccountingService
       │
       ├── Dashboard
       ├── Reports
       ├── Project
       └── Employee Performance
```

화면별로 계산식을 따로 구현하지 않고 동일한 데이터를 동일한 계산 기준으로 사용하도록 설계했습니다.

### Core Principles

- 매출과 현금 흐름 분리
- VAT 제외 공급가액 기준 손익 계산
- 입금 완료 기준 확정 매출 인식
- 완료 프로젝트 기준 확정 순이익 계산
- 직원별 기여도 중복 합산 방지
- 취소 및 파기 계약 집계 제외
- 미수금 별도 계산
- 분모 0 및 데이터 부족 상태 별도 표기

---

## Employee Contribution

프로젝트별 직원 배정 시 기여도 비율을 설정할 수 있으며, 완료 프로젝트의 실제 수익을 기준으로 직원별 성과를 계산합니다.

```text
Employee Contribution
        =
Project Net Profit
        ×
Contribution Ratio
```

한 프로젝트의 매출 또는 순이익이 여러 직원에게 중복 합산되지 않도록 계산 기준을 분리했습니다.

---

## Security

운영환경을 고려해 기본적인 Web Security 정책을 적용했습니다.

### Authentication

- `password_hash()` 기반 Password Hashing
- 로그인 실패 횟수 제한
- 일정 횟수 실패 시 계정 잠금
- 로그인 시 `session_regenerate_id()`
- 유휴 세션 자동 로그아웃
- 비활성 및 삭제 계정 재검증

### Request Security

- 모든 변경 요청 CSRF 검증
- PDO Prepared Statement
- Router 기반 Permission 검증
- HTTP Method 제한

### File Upload

업로드 파일은 DocumentRoot 외부에 저장합니다.

```text
storage/uploads/
```

- Random Filename
- Extension Validation
- MIME Validation
- Double Extension Validation
- 권한 검사 후 Download Streaming
- 직접 실행 차단

### Audit

중요한 사용자 행동은 `audit_logs`에 기록합니다.

---

## AI-assisted Development

프로젝트의 반복 개발, 검증, 테스트 및 배포 프로세스에 AI-assisted development workflow를 활용했습니다.

주요 활용 영역은 다음과 같습니다.

- 요구사항 기반 기능 구현
- 반복적인 코드 수정 및 리팩토링
- DB Schema 및 Migration 검토
- 기능별 QA 및 Regression Test
- Security Review
- Deployment Workflow 점검
- 운영 문서 자동화

AI는 단순 코드 생성 도구가 아니라 프로젝트 개발 프로세스를 빠르게 반복하고 검증하기 위한 도구로 활용했습니다.

---

## My Role

프로젝트의 기획부터 운영 구조 설계, 개발 및 검수까지 전체 과정을 담당했습니다.

- 실제 도장회사 업무 프로세스 분석
- CRM 및 영업 Pipeline 구조 설계
- 고객, 계약, 프로젝트, 공정 데이터 모델 설계
- UI/UX 및 Dashboard 구조 기획
- Accounting 및 Profitability Rule 설계
- Employee Contribution Rule 설계
- Role 및 Permission 구조 설계
- Web Application 개발
- QA 및 Security Review
- Deployment 구조 설계
- 운영 문서 작성

---

## Local Development

### 1. Database

```bash
mysql -uroot -p -e "
CREATE DATABASE eden_crm
DEFAULT CHARSET utf8mb4
COLLATE utf8mb4_unicode_ci;
"
```

### 2. Schema

```bash
mysql -ueden_crm_user -p eden_crm < database/schema.sql
mysql -ueden_crm_user -p eden_crm < database/seed_core.sql
```

개발용 Dummy Data가 필요한 경우:

```bash
mysql -ueden_crm_user -p eden_crm < database/seed_dev.sql
```

운영환경에는 `seed_dev.sql`을 적용하지 않습니다.

### 3. Configuration

```bash
cp app/config/config.local.example.php \
   app/config/config.local.php
```

DB 접속정보 등 Local Configuration을 설정합니다.

### 4. Run

```bash
php -S 127.0.0.1:8080 -t public
```

```text
http://127.0.0.1:8080/index.php?r=login
```

---

## Isolated Development Database

Local MySQL Root 접근이 어려운 환경에서는 프로젝트 전용 MySQL Instance를 별도 실행할 수 있습니다.

```bash
bash scripts/start_dev.sh
```

스크립트는 다음 작업을 자동 수행합니다.

- Development MySQL Instance 실행
- Database Initialization
- Schema Import
- Seed Import
- PHP Development Server 실행

운영 배포 시에는 실제 Hosting MySQL과 Web Server를 사용합니다.

---

## Testing

다양한 기능을 독립적으로 검증할 수 있도록 Test 및 QA Script를 구성했습니다.

```bash
php scripts/tests/run.php
```

```bash
php scripts/reconcile_qa.php
```

```bash
bash scripts/qa_smoke.sh
```

```bash
bash scripts/security_probe.sh
```

검증 범위는 다음을 포함합니다.

- CRM
- Contract
- Project
- Accounting
- Employee Performance
- RBAC
- Security
- UI Regression
- Data Reconciliation

---

## Documentation

프로젝트 내부에는 운영 및 유지보수를 위한 별도 문서를 함께 관리합니다.

```text
docs/
├── ARCHITECTURE.md
├── DB_INTERFACE.md
├── PRODUCT_MANUAL.md
├── ACCOUNTING_RULES.md
├── EMPLOYEE_CONTRIBUTION_RULES.md
├── ATTENDANCE_RULES.md
├── SALES_PIPELINE_RULES.md
├── PROCESS_FLOW.md
├── QA_REPORT.md
├── MIGRATION_REPORT.md
├── DEPLOYMENT_REPORT.md
└── ROLLBACK_GUIDE.md
```

기능 자체뿐 아니라 운영, QA, 배포, 롤백까지 프로젝트 단위로 문서화했습니다.

---

## Deployment

운영 배포 시 다음 항목을 확인합니다.

- Production DB Configuration 적용
- Development Seed 제외
- Test Account 제거 또는 Password 변경
- Storage Write Permission 확인
- `app/`, `storage/` 직접 접근 차단
- `display_errors` 비활성화
- Error Log 경로 확인
- Database Backup
- Rollback Procedure 확인

---

## Project Goal

EDEN CRM은 단순 CRM 화면을 만드는 것이 아니라 실제 시공회사의 영업, 공사, 회계, 직원 성과 데이터를 하나의 업무 흐름으로 연결하는 것을 목표로 구축했습니다.

특히 다음 문제를 해결하는 데 중점을 두었습니다.

- 고객 정보와 영업 진행상황의 분산
- 계약 이후 프로젝트 정보의 단절
- 공정 진행상황 확인의 어려움
- 실제 원가와 수익률 산정의 불일치
- 직원별 성과 산정 기준의 부재
- 여러 화면에서 서로 다른 매출 및 수익 수치가 표시되는 문제
- 내부 업무 변경 이력 및 책임 추적의 어려움

이를 해결하기 위해 데이터 구조, 권한, 회계 기준, 공정 규칙 및 운영 프로세스를 하나의 시스템 안에서 관리하도록 설계했습니다.
