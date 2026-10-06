#!/usr/bin/env bash
# CI: starts the demo image twice against PostgreSQL with the stand-in API, checks the demo
# accounts (created once, never duplicated, passwords never logged) and translates the sample
# article. Needs: docker image supertext-craft-demo, PostgreSQL at $PG_URL (psql on the host),
# the stand-in on 127.0.0.1:8765.
set -euo pipefail
PORT=8080
export DEMO_ADMIN_EMAIL=ci-admin@example.com DEMO_ADMIN_PASSWORD="ci-$(openssl rand -hex 8)"
export DEMO_EDITOR_EMAIL=ci-editor@example.com DEMO_EDITOR_PASSWORD="ci-$(openssl rand -hex 8)"
sql() { psql "${PG_URL%/*}/craft" -tAc "$1"; }

start() {
	docker rm -f demo >/dev/null 2>&1 || true
	docker run -d --name demo --network host -e PORT=$PORT -e DATABASE_URL="$PG_URL" -e CRAFT_SECURITY_KEY=ci-security-key-ci-security-key \
		-e PRIMARY_SITE_URL=http://127.0.0.1:$PORT/ -e DEMO_ADMIN_EMAIL -e DEMO_ADMIN_PASSWORD -e DEMO_EDITOR_EMAIL -e DEMO_EDITOR_PASSWORD \
		-e SUPERTEXT_API_KEY=anything -e SUPERTEXT_API_URL=http://127.0.0.1:8765/v1/ supertext-craft-demo >/dev/null
	for _ in $(seq 120); do curl -sf -o /dev/null http://127.0.0.1:$PORT/admin/login && break; sleep 2; done
	curl -sf -o /dev/null http://127.0.0.1:$PORT/admin/login
	docker logs demo 2>&1 | grep '\[demo\]' || true
}

start
start   # second start: nothing duplicated or changed
docker logs demo 2>&1 | grep > /dev/null 'DEMO_EDITOR: account exists, left unchanged'
logs=$(docker logs demo 2>&1)
if grep -qF -e "$DEMO_ADMIN_PASSWORD" -e "$DEMO_EDITOR_PASSWORD" <<< "$logs"; then echo "A password appeared in the log"; exit 1; fi

test "$(sql "select string_agg(email, ',' order by email) from users")" = "ci-admin@example.com,ci-editor@example.com"
test "$(sql "select admin from users where email='ci-admin@example.com'")" = "t"
test "$(sql "select g.handle from users u join usergroups_users ug on ug.\"userId\"=u.id join usergroups g on g.id=ug.\"groupId\" where u.email='ci-editor@example.com'")" = "editors"

docker exec demo runuser -u www-data -- php craft supertext-translation/translate/check
ENTRY=$(sql "select e.id from entries e join sections s on s.id=e.\"sectionId\" join elements el on el.id=e.id where s.handle='articles' and el.\"revisionId\" is null and el.\"draftId\" is null limit 1")
docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$ENTRY" --from=en | tee /tmp/translate.log
test "$(grep -c ': translated' /tmp/translate.log)" = 3

test "$(sql "select es.title from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='de'")" = "Schweizer Schokolade, weltweit versandt"
test "$(sql "select es.slug from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='fr'")" = "chocolat-suisse-expedie-dans-le-monde-entier"
sql "select es.content::text from elements_sites es join sites s on s.id=es.\"siteId\" where es.\"elementId\"=$ENTRY and s.handle='de'" | grep > /dev/null '<strong>Berner</strong>'
# Nested Matrix entries are translated too.
sql "select es.title from elements_sites es join sites s on s.id=es.\"siteId\" join entries e on e.id=es.\"elementId\" where e.\"primaryOwnerId\"=$ENTRY and s.handle='de'" | grep > /dev/null 'Handgefertigt in Bern'

# A second run skips the sites that now have their own text.
docker exec demo runuser -u www-data -- php craft supertext-translation/translate "$ENTRY" --from=en | grep > /dev/null 'skipped'
curl -sf "http://127.0.0.1:$PORT/de/articles/schweizer-schokolade-weltweit-versandt" | grep > /dev/null 'Von Bern in die Welt'
echo "Demo check passed"
