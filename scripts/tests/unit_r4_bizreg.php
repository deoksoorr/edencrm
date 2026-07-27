<?php
/** R4 T2 — 사업자등록번호 형식·국세청 체크섬 검증 단위 테스트 (BizReg). */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib.php';
require_once APP_PATH . '/core/BizReg.php';

echo "── BizReg::normalize ──\n";
t_true('하이픈 제거', BizReg::normalize('123-45-67891') === '1234567891');
t_true('공백·문자 제거', BizReg::normalize(' 123 45 67891a ') === '1234567891');
t_true('빈 문자열', BizReg::normalize('') === '');

echo "\n── BizReg::isValid — 유효(체크섬 일치) ──\n";
t_true('121-86-34567 (체크섬 유효 표본)', BizReg::isValid('121-86-34567'));
t_true('1218634567 (하이픈 없음)', BizReg::isValid('1218634567'));
t_true('123-45-67891', BizReg::isValid('123-45-67891'));
t_true('220-81-62517', BizReg::isValid('2208162517'));
t_true('120-81-47521', BizReg::isValid('1208147521'));

echo "\n── BizReg::isValid — 무효 ──\n";
t_true('체크섬 불일치(1234567890)', !BizReg::isValid('1234567890'));
t_true('체크섬 불일치(121-86-34568)', !BizReg::isValid('121-86-34568'));
t_true('9자리', !BizReg::isValid('123456789'));
t_true('11자리', !BizReg::isValid('12345678901'));
t_true('빈 문자열', !BizReg::isValid(''));
t_true('문자 포함(abc4567891)', !BizReg::isValid('abc4567891'));
t_true('전화번호 형태(010-1234-5678)', !BizReg::isValid('010-1234-5678'));

echo "\n── BizReg::format ──\n";
t_true('10자리 → 000-00-00000', BizReg::format('1218634567') === '121-86-34567');
t_true('이미 포맷된 입력 유지', BizReg::format('121-86-34567') === '121-86-34567');
t_true('10자리 아니면 원문 반환', BizReg::format('12345') === '12345');

exit(t_summary());
