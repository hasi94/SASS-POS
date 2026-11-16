import { readFileSync, readdirSync } from 'node:fs';
import { ApiError } from '../../api/envelope';
import { SALES_CALLS, matchCall } from './calls';
import { SalesRefusalCode, refusalOf } from './refusals';
import { Fixture, replay } from './testing/replay';
import { problemWith } from './testing/shape';
import { REFUSAL_ENVELOPE, SUCCESS_VALIDATORS, successEnvelope } from './testing/validators';

/**
 * AC-4: every checked-in fixture is replayed against the mock through the real HTTP stack. The
 * answer must equal the fixture exactly (status, and the body's JSON text with key order and
 * every character of every value significant, P-11), parse against the TypeScript types (P-13),
 * and a refusal must be a refusal the till can read. The same files are the conformance suite the
 * real route layer is run against later.
 */
const ROOT = 'projects/shared/src/lib/sales/contract/fixtures';
const FORBIDDEN_KEYS = ['branch_id', 'terminal_id', 'location_id', 'business_date'];

interface Loaded {
  name: string;
  fixture: Fixture;
}

function load(): { loaded: Loaded[]; problem: string | null } {
  const loaded: Loaded[] = [];
  let dirs: string[];
  try {
    dirs = readdirSync(ROOT).sort();
  } catch {
    return { loaded, problem: `The fixtures folder ${ROOT} is missing.` };
  }
  for (const dir of dirs) {
    for (const file of readdirSync(`${ROOT}/${dir}`).sort()) {
      const text = readFileSync(`${ROOT}/${dir}/${file}`, 'utf8');
      loaded.push({ name: `${dir}/${file}`, fixture: JSON.parse(text) as Fixture });
    }
  }
  return { loaded, problem: loaded.length === 0 ? `The fixtures folder ${ROOT} is empty.` : null };
}

const { loaded, problem } = load();

function toApiError(status: number, body: unknown): ApiError {
  const envelope = body as {
    message: string;
    errors: ApiError['errors'];
    meta: { request_id: string };
  };
  return new ApiError(status, envelope.message, envelope.errors, envelope.meta.request_id);
}

describe('contract fixtures', () => {
  it('exist', () => {
    expect(problem).toBeNull();
    expect(loaded.length).toBeGreaterThan(0);
  });

  it.each(loaded.map((l) => [l.name, l.fixture] as const))('%s', async (name, fixture) => {
    const [dir, file] = name.split('/');
    // The file says what it is, and says it consistently.
    expect(fixture.call).toBe(dir);
    expect(`${fixture.case}.json`).toBe(file);
    const matched = matchCall(fixture.request.method, fixture.request.path);
    expect(matched?.call.id).toBe(fixture.call);
    expect(JSON.stringify(fixture.request.body ?? {})).not.toMatch(
      new RegExp(`"(${FORBIDDEN_KEYS.join('|')})"`),
    );

    const answer = await replay(fixture);

    // AC-4: the mock's answer equals the fixture, status and body, key order significant.
    expect(answer.status).toBe(fixture.response.status);
    expect(JSON.stringify(answer.body)).toBe(JSON.stringify(fixture.response.body));

    // The fixture parses against the types.
    if (fixture.case.startsWith('success')) {
      expect(answer.status).toBeLessThan(300);
      expect(
        problemWith(successEnvelope(SUCCESS_VALIDATORS[fixture.call]), fixture.response.body),
      ).toBeNull();
    } else {
      const code = fixture.case.split('.')[1] as SalesRefusalCode;
      expect(fixture.case.startsWith('refused.')).toBe(true);
      expect(problemWith(REFUSAL_ENVELOPE, fixture.response.body)).toBeNull();
      expect(refusalOf(toApiError(answer.status, fixture.response.body))).toBe(code);
    }
  });

  describe('coverage', () => {
    const present = new Set(loaded.map((l) => `${l.fixture.call}/${l.fixture.case}`));

    it('has the success and every declared refusal for each call that declares fixtures', () => {
      const missing: string[] = [];
      for (const call of SALES_CALLS) {
        for (const name of call.fixtures ?? []) {
          if (!present.has(`${call.id}/${name}`)) {
            missing.push(`${call.id}/${name}`);
          }
        }
      }
      expect(missing).toEqual([]);
    });

    it('declares a success case for every call that declares fixtures', () => {
      for (const call of SALES_CALLS.filter((c) => c.fixtures)) {
        expect(call.fixtures?.some((c) => c === 'success')).toBe(true);
      }
    });

    it('has no fixture for a case the call does not declare', () => {
      const declared = new Set(
        SALES_CALLS.flatMap((c) => (c.fixtures ?? []).map((name) => `${c.id}/${name}`)),
      );
      const extra = [...present].filter((p) => !declared.has(p));
      expect(extra).toEqual([]);
    });
  });
});
