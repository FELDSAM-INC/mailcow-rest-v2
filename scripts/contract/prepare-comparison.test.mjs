import assert from 'node:assert/strict'
import { test } from 'node:test'
import { prepareComparison, assertAuditCodesRetained } from './prepare-comparison.mjs'

test('only explicitly extensible audit events are normalized, without changing the source contract', () => {
  const audit = {type: 'string', enum: ['signIn'], description: 'Audited event. Later features add values; clients must tolerate unknown\n  ones.'}
  const closed = {type: 'string', enum: ['plain'], description: 'Authentication mechanism.'}
  const document = {components:{schemas:{AuditEventType:audit, Auth:closed}}, paths:{'/events':{schema:audit}}}
  const compared = prepareComparison(document)
  assert.deepEqual(compared.components.schemas.AuditEventType['x-extensible-enum'], ['signIn'])
  assert.equal(compared.components.schemas.AuditEventType.enum, undefined)
  assert.deepEqual(compared.paths['/events'].schema['x-extensible-enum'], ['signIn'])
  assert.deepEqual(compared.components.schemas.Auth, closed)
  assert.deepEqual(audit.enum, ['signIn'])
})

test('audit target code additions are extensible but unrelated object enums stay closed', () => {
  const target = {type:'object', description:'Object the event acted on.', properties:{type:{type:'string', enum:['mailbox']}}}
  const unrelated = {type:'object', description:'A connection.', properties:{type:{type:'string',enum:['plain']}}}
  const result=prepareComparison({target,unrelated})
  assert.deepEqual(result.target.properties.type['x-extensible-enum'],['mailbox'])
  assert.deepEqual(result.unrelated,unrelated)
  assert.deepEqual(target.properties.type.enum,['mailbox'])
})

test('extensible additions never permit removing documented audit event or target codes', () => {
  const doc = (events, targets) => ({
    event: { type: 'string', enum: events, description: 'Audited event. Later features add values; clients must tolerate unknown ones.' },
    target: { type: 'object', description: 'Object the event acted on.', properties: { type: { type: 'string', enum: targets } } },
  })
  const baseline = doc(['signIn'], ['mailbox'])
  assert.doesNotThrow(() => assertAuditCodesRetained(baseline, doc(['signIn', 'groupUpdate'], ['mailbox', 'mailboxGroup'])))
  assert.throws(() => assertAuditCodesRetained(baseline, doc([], ['mailbox'])), /Removed audit events code: signIn/)
  assert.throws(() => assertAuditCodesRetained(baseline, doc(['signIn'], [])), /Removed audit targets code: mailbox/)
})
