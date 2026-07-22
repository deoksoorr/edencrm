<?php
/**
 * 인증: 로그인 폼/처리, 로그아웃, 비밀번호 변경(최초/일반).
 */
class AuthController
{
    public function loginForm(): void
    {
        if (Auth::check()) {
            Response::redirect('home');
        }
        View::render('auth/login', ['title' => '로그인'], 'blank');
    }

    public function login(): void
    {
        $loginId = Util::postStr('login_id');
        $password = (string) ($_POST['password'] ?? '');
        if ($loginId === '' || $password === '') {
            Response::redirect('login', [], '아이디와 비밀번호를 입력하세요.', 'error');
        }
        $reason = null;
        if (Auth::attempt($loginId, $password, $reason)) {
            $u = Auth::user();
            if ($u && (int) $u['must_change_password'] === 1) {
                Response::redirect('password.change', [], '보안을 위해 비밀번호를 변경하세요.', 'warning');
            }
            Response::redirect('home', [], '환영합니다, ' . $u['name'] . '님.');
        }
        Response::redirect('login', [], $reason ?? '로그인에 실패했습니다.', 'error');
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('login', [], '로그아웃되었습니다.');
    }

    public function changeForm(): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        View::render('auth/change-password', [
            'title'   => '비밀번호 변경',
            'forced'  => (int) $u['must_change_password'] === 1,
        ], (int) $u['must_change_password'] === 1 ? 'blank' : 'default');
    }

    public function changePassword(): void
    {
        Auth::requireLogin();
        $u = Auth::user();
        $forced = (int) $u['must_change_password'] === 1;
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');

        $err = null;
        // 강제 변경이 아니면 현재 비밀번호 확인
        if (!$forced && !password_verify($current, $u['password_hash'])) {
            $err = '현재 비밀번호가 올바르지 않습니다.';
        } elseif (strlen($new) < 8) {
            $err = '새 비밀번호는 8자 이상이어야 합니다.';
        } elseif (!preg_match('/[A-Za-z]/', $new) || !preg_match('/[0-9]/', $new)) {
            $err = '새 비밀번호는 영문과 숫자를 포함해야 합니다.';
        } elseif ($new !== $confirm) {
            $err = '새 비밀번호 확인이 일치하지 않습니다.';
        } elseif (password_verify($new, $u['password_hash'])) {
            $err = '이전과 다른 비밀번호를 사용하세요.';
        }
        if ($err) {
            Response::redirect('password.change', [], $err, 'error');
        }

        Db::update('users', [
            'password_hash'        => password_hash($new, PASSWORD_BCRYPT),
            'must_change_password' => 0,
        ], 'id = :id', [':id' => $u['id']]);
        Audit::log('password_change', 'users', (int) $u['id'], null, null);

        Response::redirect('home', [], '비밀번호가 변경되었습니다.');
    }
}
