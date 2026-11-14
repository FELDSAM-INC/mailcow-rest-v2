import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { runInNewContext } from 'node:vm'

const workflow = readFileSync(new URL('../../.github/workflows/ci.yml', import.meta.url), 'utf8')
function job(name) {
  const block = workflow.split(`\n  ${name}:\n`)[1]?.split(/\n  [a-z][a-z-]*:\n/)[0]
  assert.ok(block, `Missing ${name} job`)
  return block
}
function enabled(name, event, ref) {
  const condition = job(name).match(/^    if: >-\n((?:      .*\n)+)/m)?.[1].trim()
  assert.ok(condition, `${name} must have an event/ref gate`)
  return runInNewContext(`(${condition})`, {
    github: { event_name: event, ref },
    startsWith: (value, prefix) => value.startsWith(prefix),
  }, { timeout: 100 })
}

test('PR checks have read-only contents permission and no package authority', () => {
  const permissions = job('image').split('    permissions:\n')[1]?.split('    strategy:')[0]
  assert.equal(permissions?.trim(), 'contents: read')
  for (const event of ['pull_request', 'pull_request_target']) {
    for (const ref of ['refs/pull/1/merge', 'refs/heads/main', 'refs/tags/v0.1.0-preview.5']) {
      assert.equal(enabled('image', event, ref), true)
      assert.equal(enabled('image-publish', event, ref), false)
      assert.equal(enabled('publish', event, ref), false)
    }
  }
})
test('package writers run only on trusted main/tag publication events', () => {
  for (const name of ['image-publish', 'publish']) {
    assert.match(job(name), /packages: write/)
    assert.equal(enabled(name, 'workflow_dispatch', 'refs/heads/contributor'), false)
    for (const event of ['push', 'workflow_dispatch']) {
      for (const ref of ['refs/heads/main', 'refs/tags/v0.1.0-preview.5']) {
        assert.equal(enabled(name, event, ref), true)
        assert.equal(enabled('image', event, ref), false)
      }
    }
  }
})
test('PR and publication builds share steps and native-platform cache inputs', () => {
  assert.match(job('image'), /steps: &image_steps/)
  assert.match(job('image'), /strategy: &image_strategy/)
  assert.match(job('image-publish'), /steps: \*image_steps/)
  assert.match(job('image-publish'), /strategy: \*image_strategy/)
  assert.match(job('publish'), /needs: \[[^\n]*image-publish/)
})
