// Audit codes require clients to tolerate unknown values. Make that
// documented extensibility machine-readable for oasdiff, including pre-extension release specs.
// No other enum is relaxed; removals and every other compatibility check still run normally.
import { readFileSync, writeFileSync } from 'node:fs'
import { pathToFileURL } from 'node:url'

const auditDescription = 'Audited event. Later features add values; clients must tolerate unknown ones.'

export function assertAuditCodesRetained(base, current) {
  function codes(document) {
    const result = { events: new Set(), targets: new Set() }
    function visit(node) {
      if (!node || typeof node !== 'object') return
      if (node.type === 'string' && Array.isArray(node.enum) &&
          node.description?.replace(/\s+/g, ' ').trim() === auditDescription) {
        node.enum.forEach(value => result.events.add(value))
      }
      if (node.type === 'object' && node.description === 'Object the event acted on.' &&
          Array.isArray(node.properties?.type?.enum)) {
        node.properties.type.enum.forEach(value => result.targets.add(value))
      }
      Object.values(node).forEach(visit)
    }
    visit(document)
    return result
  }
  const before = codes(base), after = codes(current)
  for (const kind of ['events', 'targets']) {
    for (const value of before[kind]) {
      if (!after[kind].has(value)) throw new Error(`Removed audit ${kind} code: ${value}`)
    }
  }
}

export function prepareComparison(document) {
  const copy = structuredClone(document)
  function visit(node) {
    if (!node || typeof node !== 'object') return
    if (node.type === 'string' && Array.isArray(node.enum) &&
        node.description?.replace(/\s+/g, ' ').trim() === auditDescription) {
      node['x-extensible-enum'] = node.enum
      delete node.enum
    }
    // Audit target codes share the audit viewer's documented unknown-code fallback.
    if (node.type === 'object' && node.description === 'Object the event acted on.' &&
        Array.isArray(node.properties?.type?.enum)) {
      node.properties.type['x-extensible-enum'] = node.properties.type.enum
      delete node.properties.type.enum
    }
    for (const value of Object.values(node)) visit(value)
  }
  visit(copy)
  return copy
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  const [input, output, baseline] = process.argv.slice(2)
  if (!input || !output || input === output) throw new Error('usage: prepare-comparison.mjs input.json output.json [baseline.json]')
  const document = JSON.parse(readFileSync(input, 'utf8'))
  if (baseline) assertAuditCodesRetained(JSON.parse(readFileSync(baseline, 'utf8')), document)
  writeFileSync(output, JSON.stringify(prepareComparison(document), null, 2) + '\n')
}
