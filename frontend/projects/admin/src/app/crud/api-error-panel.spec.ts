import { TestBed } from '@angular/core/testing';
import { ApiError } from '@flexipos/shared';
import { ApiErrorPanel } from './api-error-panel';
import { FORBIDDEN_MESSAGE } from './describe-api-error';

async function render(error: unknown) {
  const fixture = TestBed.createComponent(ApiErrorPanel);
  fixture.componentRef.setInput('error', error);
  await fixture.whenStable();
  return fixture;
}

describe('ApiErrorPanel (T-036 AC-8, AC-16)', () => {
  it('is an alert showing the message and the request id', async () => {
    const fixture = await render(new ApiError(500, 'Server broke.', [], 'req-77'));
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('[role="alert"]')).not.toBeNull();
    expect(el.textContent).toContain('Server broke.');
    expect(el.textContent).toContain('Request id: req-77');
  });

  it('omits the request id line when there is none', async () => {
    const fixture = await render(new ApiError(0, 'Cannot reach the server.'));
    expect((fixture.nativeElement as HTMLElement).textContent).not.toContain('Request id');
  });

  it('emits retry when Retry is pressed', async () => {
    const fixture = await render(new ApiError(500, 'x'));
    let emitted = 0;
    fixture.componentInstance.retry.subscribe(() => (emitted += 1));
    const button = (fixture.nativeElement as HTMLElement).querySelector(
      'button',
    ) as HTMLButtonElement;
    expect(button.textContent?.trim()).toBe('Retry');
    button.click();
    expect(emitted).toBe(1);
  });

  it('shows the permission text for a 403', async () => {
    const fixture = await render(new ApiError(403, 'Raw server words.'));
    const text = (fixture.nativeElement as HTMLElement).textContent;
    expect(text).toContain(FORBIDDEN_MESSAGE);
    expect(text).not.toContain('Raw server words.');
  });

  it('renders markup in a message as literal text', async () => {
    const fixture = await render(new ApiError(500, 'Bad <b>bold</b> <img src=x onerror=alert(1)>'));
    const el = fixture.nativeElement as HTMLElement;
    expect(el.querySelector('b')).toBeNull();
    expect(el.querySelector('img')).toBeNull();
    expect(el.textContent).toContain('<b>bold</b>');
  });

  it('can hide Retry', async () => {
    const fixture = TestBed.createComponent(ApiErrorPanel);
    fixture.componentRef.setInput('error', new ApiError(500, 'x'));
    fixture.componentRef.setInput('retryable', false);
    await fixture.whenStable();
    expect((fixture.nativeElement as HTMLElement).querySelector('button')).toBeNull();
  });
});
