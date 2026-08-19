#!/usr/bin/env bash
#
# One way of asking the instance's database a question, shared by the scripts
# that check what the setup actually installed.
#
# mysqli rather than a mysql client: PHP is already a hard requirement for
# running the setup, a client binary is not, and one fewer thing to be installed
# on the machine running this is one fewer way for it to fail somewhere that is
# not about this extension.
#
# Reads DB_HOST / DB_PORT / DB_USER / DB_PWD / DB_NAME from the environment -
# the caller exports them. Sourced, never executed.
#
# @copyright   Copyright (C) 2026 Altioo
# @license     https://www.gnu.org/licenses/agpl-3.0.html AGPL-3.0-or-later

itop_sql() {
	DB_QUERY="$1" php -r '
		$o = @new mysqli(getenv("DB_HOST"), getenv("DB_USER"), getenv("DB_PWD"), getenv("DB_NAME"), (int)getenv("DB_PORT"));
		if ($o->connect_errno) { fwrite(STDERR, $o->connect_error."\n"); exit(1); }
		$r = $o->query(getenv("DB_QUERY"));
		if ($r === false) { fwrite(STDERR, $o->error."\n"); exit(1); }
		$a = $r->fetch_row();
		echo $a === null ? "" : (string)$a[0];
	'
}
