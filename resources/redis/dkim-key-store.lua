-- Atomic changes of one DKIM key in mailcow's Redis (feature 010 research R4, data-model "Writes").
--
-- KEYS: DKIM_PUB_KEYS, DKIM_SELECTORS, DKIM_PRIV_KEYS
-- ARGV: mode, name, expected fingerprint ('' = no check), selector, public key, private key
--
-- The fingerprint is sha1(name \0 selector \0 public key \0 private key) of the stored key, with
-- absent values as ''. It never leaves the server and PHP (App\Clients\SharedStore\DkimKeyStore).
--
-- Modes and answers:
--   fingerprint  {'ok', fingerprint}
--   create       {'exists'} when a public key or a selector is stored, else {'ok', '', ''}
--   replace      {'outdated'} when the expected fingerprint differs, else {'ok', old selector, old public key,
--                old private key} (the old fields let the caller restore them when its audit record fails)
--   delete       {'absent'} without a key, {'outdated'}, else {'ok', old selector, old public key, old private key}
--   restore      {'outdated'} unless the current fingerprint is the expected one; then the given
--                fields replace the current ones ('' = absent): {'ok'}

local pubKeys, selectors, privKeys = KEYS[1], KEYS[2], KEYS[3]
local mode, name, expected = ARGV[1], ARGV[2], ARGV[3]

local pub = redis.call('HGET', pubKeys, name)
local sel = redis.call('HGET', selectors, name)
local priv = false
if sel then
  priv = redis.call('HGET', privKeys, sel .. '.' .. name)
end

local function s(value)
  if value then return value end
  return ''
end

local fingerprint = redis.sha1hex(name .. '\0' .. s(sel) .. '\0' .. s(pub) .. '\0' .. s(priv))

local function removeCurrent()
  redis.call('HDEL', pubKeys, name)
  redis.call('HDEL', selectors, name)
  if sel then
    redis.call('HDEL', privKeys, sel .. '.' .. name)
  end
end

local function write(newSel, newPub, newPriv)
  if newPub ~= '' then redis.call('HSET', pubKeys, name, newPub) end
  if newSel ~= '' then
    redis.call('HSET', selectors, name, newSel)
    if newPriv ~= '' then redis.call('HSET', privKeys, newSel .. '.' .. name, newPriv) end
  end
end

if mode == 'fingerprint' then
  return {'ok', fingerprint}
end

if mode == 'create' then
  if pub or sel then return {'exists'} end
  write(ARGV[4], ARGV[5], ARGV[6])
  return {'ok', '', ''}
end

if mode == 'replace' then
  if expected ~= '' and expected ~= fingerprint then return {'outdated'} end
  removeCurrent()
  write(ARGV[4], ARGV[5], ARGV[6])
  return {'ok', s(sel), s(pub), s(priv)}
end

if mode == 'delete' then
  if not pub and not sel then return {'absent'} end
  if expected ~= '' and expected ~= fingerprint then return {'outdated'} end
  removeCurrent()
  return {'ok', s(sel), s(pub), s(priv)}
end

if mode == 'restore' then
  if expected ~= fingerprint then return {'outdated'} end
  removeCurrent()
  write(ARGV[4], ARGV[5], ARGV[6])
  return {'ok'}
end

return redis.error_reply('unknown mode ' .. tostring(mode))
