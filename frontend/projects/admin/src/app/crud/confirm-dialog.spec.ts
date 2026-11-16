import { ChangeDetectionStrategy, Component, signal } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { ConfirmDialog } from './confirm-dialog';

@Component({
  selector: 'adm-host',
  imports: [ConfirmDialog],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button type="button" id="trigger" (click)="open.set(true)">Delete</button>
    @if (open()) {
      <adm-confirm-dialog
        name="Drinks"
        (confirmed)="confirmed = confirmed + 1"
        (cancelled)="cancelled = cancelled + 1; open.set(false)"
      />
    }
  `,
})
class Host {
  readonly open = signal(false);
  confirmed = 0;
  cancelled = 0;
}

async function openDialog() {
  const fixture = TestBed.createComponent(Host);
  await fixture.whenStable();
  const root = fixture.nativeElement as HTMLElement;
  const trigger = root.querySelector('#trigger') as HTMLButtonElement;
  trigger.focus();
  trigger.click();
  await fixture.whenStable();
  return { fixture, root, trigger };
}

function key(target: Element, name: string, shiftKey = false): KeyboardEvent {
  const event = new KeyboardEvent('keydown', {
    key: name,
    shiftKey,
    bubbles: true,
    cancelable: true,
  });
  target.dispatchEvent(event);
  return event;
}

describe('ConfirmDialog (T-036 AC-19)', () => {
  it('is a modal dialog labelled by its title, which names the row', async () => {
    const { root } = await openDialog();
    const dialog = root.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog.getAttribute('aria-modal')).toBe('true');
    const title = root.querySelector(`#${dialog.getAttribute('aria-labelledby')}`);
    expect(title?.textContent?.trim()).toBe('Delete Drinks?');
  });

  it('moves focus inside the dialog when it opens, onto Cancel', async () => {
    const { root } = await openDialog();
    const dialog = root.querySelector('[role="dialog"]') as HTMLElement;
    expect(dialog.contains(document.activeElement)).toBe(true);
    expect(document.activeElement?.textContent?.trim()).toBe('Cancel');
  });

  it('emits cancelled on Escape and confirmed on Delete', async () => {
    const { fixture, root } = await openDialog();
    const dialog = root.querySelector('[role="dialog"]') as HTMLElement;
    const buttons = dialog.querySelectorAll('button');
    buttons[1].click();
    expect(fixture.componentInstance.confirmed).toBe(1);
    const event = key(dialog, 'Escape');
    expect(event.defaultPrevented).toBe(true);
    expect(fixture.componentInstance.cancelled).toBe(1);
  });

  it('returns focus to the trigger when the dialog closes', async () => {
    const { fixture, root, trigger } = await openDialog();
    key(root.querySelector('[role="dialog"]') as HTMLElement, 'Escape');
    await fixture.whenStable();
    expect(root.querySelector('[role="dialog"]')).toBeNull();
    expect(document.activeElement).toBe(trigger);
  });

  it('keeps Tab inside the dialog, in both directions', async () => {
    const { root } = await openDialog();
    const dialog = root.querySelector('[role="dialog"]') as HTMLElement;
    const [cancel, del] = Array.from(dialog.querySelectorAll('button'));
    del.focus();
    const forward = key(del, 'Tab');
    expect(forward.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(cancel);
    const backward = key(cancel, 'Tab', true);
    expect(backward.defaultPrevented).toBe(true);
    expect(document.activeElement).toBe(del);
  });

  it('leaves Tab alone between the two buttons', async () => {
    const { root } = await openDialog();
    const cancel = root.querySelector('[role="dialog"] button') as HTMLButtonElement;
    expect(key(cancel, 'Tab').defaultPrevented).toBe(false);
  });
});
