#!/usr/bin/env python3
"""One-time root provisioning; never prints passwords or changes application data."""
import os
import pwd
import secrets
import subprocess
from pathlib import Path

assert os.geteuid() == 0

for owner in ['deploy', 'salon-admin']:
    account = pwd.getpwnam(owner)
    directory = Path(account.pw_dir) / '.config'
    directory.mkdir(exist_ok=True, mode=0o700)
    os.chown(directory, account.pw_uid, account.pw_gid)
    os.chmod(directory, 0o700)

def sql(statement):
    subprocess.run(['mariadb'], input=statement, text=True, check=True, stdout=subprocess.DEVNULL)

def save(path, text, owner):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    account = pwd.getpwnam(owner)
    os.chown(path.parent, account.pw_uid, account.pw_gid)
    os.chmod(path.parent, 0o700)
    # Exclusive creation prevents accidental credential rotation on a repeat run.
    with path.open('x') as file:
        file.write(text)
    os.chown(path, account.pw_uid, account.pw_gid)
    os.chmod(path, 0o600)

accounts = [
    ('salon_migrator', 'SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES', '/home/deploy/.config/salon/migrations.env', 'deploy'),
    ('salon_readonly', 'SELECT', '/home/salon-admin/.config/salon/readonly.cnf', 'salon-admin'),
    ('salon_dba', 'ALL PRIVILEGES', '/home/salon-admin/.my.cnf', 'salon-admin'),
    ('salon_backup', 'SELECT, SHOW VIEW, TRIGGER, EVENT', '/etc/salon/backup.cnf', 'root'),
]
for name, rights, path, owner in accounts:
    if Path(path).exists():
        raise RuntimeError('Already provisioned: '+path)
    password = secrets.token_hex(32)
    sql(f"CREATE USER '{name}'@'127.0.0.1' IDENTIFIED BY '{password}'; GRANT {rights} ON salon_danse.* TO '{name}'@'127.0.0.1';")
    if name == 'salon_backup':
        sql("GRANT SELECT ON mysql.proc TO 'salon_backup'@'127.0.0.1';")
    text = f"DB_USERNAME={name}\nDB_PASSWORD={password}\n" if name == 'salon_migrator' else f"[client]\nhost=127.0.0.1\nuser={name}\npassword={password}\n" + ('' if name == 'salon_backup' else 'database=salon_danse\n')
    save(path, text, owner)

# Runtime cannot change the schema, grant privileges or delete the database.
sql("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'salon_app'@'127.0.0.1'; GRANT SELECT, INSERT, UPDATE, DELETE ON salon_danse.* TO 'salon_app'@'127.0.0.1';")
print('Database accounts provisioned; runtime limited to SELECT/INSERT/UPDATE/DELETE.')
