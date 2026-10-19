<?php

namespace App\Support\Redis;

/**
 * Lua scripts that change mailcow's shared store atomically (feature 006 research R12, R13), run
 * through App\Clients\SharedStore\SharedStore::evaluate(). Redis runs a script without
 * interleaving other clients' commands, so a check and its write cannot be separated by another
 * writer (the legacy UI, Rspamd).
 */
final class LuaScripts
{
    /**
     * Compare-and-set of one hash field. KEYS[1] the hash; ARGV[1] the field; ARGV[2] `1` when a
     * value is expected (ARGV[3]) and `0` when the field is expected to be absent; ARGV[4] `1` to
     * set the field to ARGV[5], `0` to delete it. Returns 1 when the field was written or deleted,
     * 0 when its value was not the expected one (nothing changed), 2 when a field expected absent
     * was to be deleted (nothing to do).
     */
    public const COMPARE_AND_SET_FIELD = <<<'LUA'
        local current = redis.call('HGET', KEYS[1], ARGV[1])
        if ARGV[2] == '1' then
          if current ~= ARGV[3] then return 0 end
        elseif current ~= false then
          return 0
        end
        if ARGV[4] == '1' then
          redis.call('HSET', KEYS[1], ARGV[1], ARGV[5])
          return 1
        end
        if current == false then return 2 end
        redis.call('HDEL', KEYS[1], ARGV[1])
        return 1
        LUA;

    /**
     * Reset of a rate-limit bucket. KEYS[1] the bucket (Rspamd's counters, a hash); KEYS[2] the
     * rate-limited log (`RL_LOG`, JSON lines). Deletes the bucket when it is a hash and removes
     * every log line whose decoded `rl_hash` equals the bucket's name; undecodable lines are
     * skipped. Returns `{countersExisted (0|1), logEntriesRemoved}`.
     */
    public const RESET_RATE_LIMIT_BUCKET = <<<'LUA'
        local existed = 0
        if redis.call('TYPE', KEYS[1]).ok == 'hash' then
          redis.call('DEL', KEYS[1])
          existed = 1
        end
        local removed = 0
        for _, line in ipairs(redis.call('LRANGE', KEYS[2], 0, -1)) do
          local ok, entry = pcall(cjson.decode, line)
          if ok and type(entry) == 'table' and entry['rl_hash'] == KEYS[1] then
            removed = removed + redis.call('LREM', KEYS[2], 0, line)
          end
        end
        return {existed, removed}
        LUA;
}
