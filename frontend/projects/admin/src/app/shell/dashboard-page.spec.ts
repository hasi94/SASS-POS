import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { TestBed } from '@angular/core/testing';
import { ALL_CATALOG_PERMISSIONS } from '@flexipos/shared';
import { seedSession } from '../crud/testing/crud-testing';
import { DashboardPage } from './dashboard-page';

describe('DashboardPage (T-036 AC-57)', () => {
  beforeEach(() => {
    sessionStorage.clear();
    seedSession(ALL_CATALOG_PERMISSIONS);
    TestBed.configureTestingModule({
      providers: [provideHttpClient(), provideHttpClientTesting()],
    });
  });

  it('renders its heading and the company and branch', async () => {
    const fixture = TestBed.createComponent(DashboardPage);
    await fixture.whenStable();
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('h1')?.textContent).toBe('Dashboard');
    expect(el.textContent).toContain('ABC Pharmacy');
    expect(el.textContent).toContain('Colombo 03');
  });

  it('gains no catalog statistics, even for a holder of every catalog key', async () => {
    const fixture = TestBed.createComponent(DashboardPage);
    await fixture.whenStable();
    expect((fixture.nativeElement as HTMLElement).textContent?.toLowerCase()).not.toContain(
      'catalog',
    );
  });
});
