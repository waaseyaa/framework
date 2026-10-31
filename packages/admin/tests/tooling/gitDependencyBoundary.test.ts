import { describe, expect, it } from 'vitest'
import packageLock from '../../package-lock.json'

type LockPackage = {
  name?: string
  resolved?: string
  dependencies?: Record<string, string>
  optionalDependencies?: Record<string, string>
}

const lock = packageLock as {
  packages: Record<string, LockPackage>
}
const wrappers = ['simple-git', '@simple-git/argv-parser']

describe('Admin Git execution dependency boundary', () => {
  it('contains no removed Git wrapper, including nested or aliased packages', () => {
    const installed = Object.entries(lock.packages).filter(([path, pkg]) => wrappers.some(name =>
      path.endsWith(`node_modules/${name}`)
      || pkg.name === name
      || pkg.resolved?.startsWith(`https://registry.npmjs.org/${name}/-/`),
    ))
    expect(installed.map(([path]) => path)).toEqual([])
  })

  it('contains no dependency edge requesting either removed wrapper', () => {
    const edges = Object.entries(lock.packages).flatMap(([path, pkg]) =>
      Object.entries({ ...pkg.dependencies, ...pkg.optionalDependencies })
        .filter(([name, range]) => wrappers.includes(name) || wrappers.some(wrapper => range.startsWith(`npm:${wrapper}@`)))
        .map(([name]) => `${path}:${name}`),
    )
    expect(edges).toEqual([])
  })
})
