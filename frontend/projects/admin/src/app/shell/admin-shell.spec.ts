import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { provideRouter, withComponentInputBinding } from '@angular/router';
import { RouterTestingHarness } from '@angular/router/testing';
import { ALL_CATALOG_PERMISSIONS, AuthService, CATALOG_PERMISSIONS } from '@flexipos/shared';
import { routes } from '../app.routes';
import { seedSession } from '../crud/testing/crud-testing';
import { AdminShell } from './admin-shell';

function setup(): HttpTestingController {
  TestBed.configureTestingModule({
    providers: [
      provideRouter(routes, withComponentInputBinding()),
      provideHttpClient(),
      provideHttpClientTesting(),
    ],
  });
  return TestBed.inject(HttpTestingController);
}

const links = (root: HTMLElement) => Array.from(root.querySelectorAll<HTMLAnchorElement>('nav a'));
const linkTexts = (root: HTMLElement) => links(root).map((a) => a.textContent?.trim());

describe('AdminShell menu (T-036 AC-4, AC-5, AC-10, AC-11, AC-12, AC-56)', () => {
  beforeEach(() => sessionStorage.clear());

  it('shows the whole catalog menu to a holder of every key, as real links', async () => {
    seedSession(ALL_CATALOG_PERMISSIONS);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    const root = harness.fixture.nativeElement as HTMLElement;
    await harness.fixture.whenStable();
    expect(linkTexts(root)).toEqual([
      'Dashboard',
      'Categories',
      'Brands',
      'Units',
      'Products',
      'Modifiers',
    ]);
    for (const a of links(root)) {
      expect(a.getAttribute('href'), a.textContent ?? '').toMatch(/^\/\w/);
    }
  });

  it('shows only Products under Catalog to a holder of products.view alone (AC-4)', async () => {
    seedSession([CATALOG_PERMISSIONS.products.view]);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    const root = harness.fixture.nativeElement as HTMLElement;
    expect(linkTexts(root)).toEqual(['Dashboard', 'Products']);
    expect(root.textContent).toContain('Catalog');
  });

  it('shows no Catalog group, and keeps Dashboard, with no catalog key (AC-5)', async () => {
    seedSession([]);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    const root = harness.fixture.nativeElement as HTMLElement;
    expect(linkTexts(root)).toEqual(['Dashboard']);
    expect(root.querySelector('nav [role="group"]')).toBeNull();
    expect(root.querySelector('nav')?.textContent).not.toContain('Catalog');
  });

  it('never lists Customers (AC-56)', async () => {
    seedSession(ALL_CATALOG_PERMISSIONS);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    expect(
      (harness.fixture.nativeElement as HTMLElement).querySelector('nav')?.textContent,
    ).not.toContain('Customers');
  });

  it('gives the nav the label Main and the Catalog group a labelled role=group (AC-11)', async () => {
    seedSession(ALL_CATALOG_PERMISSIONS);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    const root = harness.fixture.nativeElement as HTMLElement;
    expect(root.querySelector('nav')?.getAttribute('aria-label')).toBe('Main');
    const group = root.querySelector('nav [role="group"]') as HTMLElement;
    const title = root.querySelector(`#${group.getAttribute('aria-labelledby')}`);
    expect(title?.textContent?.trim()).toBe('Catalog');
  });

  it('marks the active entry with aria-current=page after navigation, and only that one (AC-11)', async () => {
    seedSession(ALL_CATALOG_PERMISSIONS);
    setup();
    const harness = await RouterTestingHarness.create('/dashboard');
    const root = harness.fixture.nativeElement as HTMLElement;
    const current = () =>
      links(root)
        .filter((a) => a.getAttribute('aria-current') === 'page')
        .map((a) => a.textContent?.trim());
    await harness.fixture.whenStable();
    expect(current()).toEqual(['Dashboard']);

    await harness.navigateByUrl('/catalog/products');
    await harness.fixture.whenStable();
    expect(current()).toEqual(['Products']);
  });

  it('renders without a branch, a company name or a user, and without empty placeholders (AC-12)', async () => {
    seedSession([], { withContext: false, withUser: false });
    setup();
    const fixture = TestBed.createComponent(AdminShell);
    await fixture.whenStable();
    const bar = (fixture.nativeElement as HTMLElement).querySelector('header.bar') as HTMLElement;
    expect(bar.querySelector('strong')).toBeNull();
    expect(bar.querySelectorAll('.fp-muted')).toHaveLength(0);
    for (const span of Array.from(bar.querySelectorAll('span:not(.spacer)'))) {
      expect(span.textContent?.trim(), span.outerHTML).not.toBe('');
    }
    expect(bar.textContent).not.toContain('undefined');
    expect(bar.textContent).not.toContain('null');
    expect(Array.from(bar.querySelectorAll('button')).map((b) => b.textContent?.trim())).toEqual([
      'Switch',
      'Sign out',
    ]);
  });

  it('shows the company, branch and user when they are present (control for AC-12)', async () => {
    seedSession([]);
    setup();
    const fixture = TestBed.createComponent(AdminShell);
    await fixture.whenStable();
    const bar = (fixture.nativeElement as HTMLElement).querySelector('header.bar') as HTMLElement;
    expect(bar.textContent).toContain('ABC Pharmacy');
    expect(bar.textContent).toContain('Colombo 03');
    expect(bar.textContent).toContain('Demo Owner');
  });

  it('hides Catalog when the context is cleared, and when the next person has no keys (AC-10)', async () => {
    seedSession([CATALOG_PERMISSIONS.products.view]);
    const http = setup();
    const auth = TestBed.inject(AuthService);
    const fixture = TestBed.createComponent(AdminShell);
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    expect(linkTexts(root)).toEqual(['Dashboard', 'Products']);

    // Switching company clears the session's keys with it.
    auth.clearContext();
    await fixture.whenStable();
    expect(linkTexts(root)).toEqual(['Dashboard']);

    // A second person signs in and picks a company whose reply carries no keys.
    auth.login('b@example.test', 'x').subscribe();
    http.expectOne('/api/v1/auth/login').flush({
      access_token: 'pat',
      refresh_token: 'prt',
      tenants: [],
      default_tenant_id: null,
      must_change_password: false,
    });
    auth.selectTenant('t').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush({
      access_token: 'tat',
      refresh_token: 'trt',
      tenant_id: 'T',
      tenant_code: 't',
    });
    await fixture.whenStable();
    expect(linkTexts(root)).toEqual(['Dashboard']);
    http.verify();
  });

  it('shows Catalog entries again when the reply grants keys (AC-10 control)', async () => {
    seedSession([]);
    const http = setup();
    const auth = TestBed.inject(AuthService);
    const fixture = TestBed.createComponent(AdminShell);
    await fixture.whenStable();
    const root = fixture.nativeElement as HTMLElement;
    expect(linkTexts(root)).toEqual(['Dashboard']);

    auth.selectTenant('t').subscribe();
    http.expectOne('/api/v1/auth/tenant').flush({
      access_token: 'tat',
      refresh_token: 'trt',
      tenant_id: 'TEN001',
      tenant_code: 't',
      permissions: [CATALOG_PERMISSIONS.units.view],
    });
    await fixture.whenStable();
    expect(linkTexts(root)).toEqual(['Dashboard', 'Units']);
    http.verify();
  });
});
