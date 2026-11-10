<?php
declare(strict_types=1);

/** Registration regression at the public HTTP boundary, replacing old index.php class extraction. */
require __DIR__ . '/support/http_fixture.php';
httpFixtureGuard(); httpFixtureReset(); $server = httpStartServer();
try {
    $staff = httpLogin($server, 'librarian');
    $users = $staff->request('GET', '/index.php?page=users');
    $csrf = httpCsrf($users['body']);
    $before = httpFixtureCount('usuarios');
    httpSame(303, $staff->request('POST', '/index.php', ['action'=>'create_user','csrf'=>$csrf,'name'=>'Ana Pérez','email'=>'ana@example.test'])['status'], 'Staff registration returns PRG.');
    httpSame($before + 1, httpFixtureCount('usuarios'), 'Registration persists exactly one user.');
    httpSame(422, $staff->request('POST', '/index.php', ['action'=>'create_user','csrf'=>$csrf,'name'=>'','email'=>'bad'])['status'], 'Invalid registration is rejected.');
    httpSame($before + 1, httpFixtureCount('usuarios'), 'Invalid registration leaves users unchanged.');
    echo "OK usuario_test\n";
} finally { httpStopServer($server); httpFixtureReset(); }
