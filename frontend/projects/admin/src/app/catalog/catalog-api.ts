import { Injectable, inject } from '@angular/core';
import {
  ApiClient,
  ApiResult,
  BarcodeCreate,
  BarcodeUpdate,
  Brand,
  BrandWrite,
  CATALOG_ENDPOINTS,
  Category,
  CategoryWrite,
  ListParams,
  Modifier,
  ModifierGroup,
  ModifierGroupWrite,
  ModifierWrite,
  Product,
  ProductBarcode,
  ProductCreate,
  ProductUpdate,
  Unit,
  UnitConversion,
  UnitConversionCreate,
  UnitCreate,
  UnitUpdate,
} from '@flexipos/shared';
import { Observable } from 'rxjs';

/** The five calls every catalog entity has (T-036 plan, "Endpoints"). */
export interface CatalogResource<T, C, U> {
  list(params: ListParams): Observable<ApiResult<T[]>>;
  get(id: string): Observable<T>;
  create(body: C): Observable<T>;
  update(id: string, body: U): Observable<T>;
  /** A delete answers 200 with `data: null`. */
  remove(id: string): Observable<null>;
}

/** Unit conversions cannot be edited: only get, create and delete exist. */
export type CatalogReadCreateDelete<T, C> = Omit<CatalogResource<T, C, never>, 'update'>;

function withoutUpdate<T, C>(
  resource: CatalogResource<T, C, never>,
): CatalogReadCreateDelete<T, C> {
  return {
    list: resource.list,
    get: resource.get,
    create: resource.create,
    remove: resource.remove,
  };
}

/**
 * The typed calls of the catalog screens. Paths come from `CATALOG_ENDPOINTS` (also used by
 * the mock), verbs are GET, POST, PUT and DELETE. PROVISIONAL pending B-1.
 */
@Injectable({ providedIn: 'root' })
export class CatalogApi {
  private readonly api = inject(ApiClient);

  readonly categories = this.resource<Category, CategoryWrite, CategoryWrite>(
    CATALOG_ENDPOINTS.categories,
    CATALOG_ENDPOINTS.category,
  );
  readonly brands = this.resource<Brand, BrandWrite, BrandWrite>(
    CATALOG_ENDPOINTS.brands,
    CATALOG_ENDPOINTS.brand,
  );
  readonly units = this.resource<Unit, UnitCreate, UnitUpdate>(
    CATALOG_ENDPOINTS.units,
    CATALOG_ENDPOINTS.unit,
  );
  readonly unitConversions: CatalogReadCreateDelete<UnitConversion, UnitConversionCreate> =
    withoutUpdate(
      this.resource<UnitConversion, UnitConversionCreate, never>(
        CATALOG_ENDPOINTS.unitConversions,
        CATALOG_ENDPOINTS.unitConversion,
      ),
    );
  readonly products = this.resource<Product, ProductCreate, ProductUpdate>(
    CATALOG_ENDPOINTS.products,
    CATALOG_ENDPOINTS.product,
  );
  readonly modifierGroups = this.resource<ModifierGroup, ModifierGroupWrite, ModifierGroupWrite>(
    CATALOG_ENDPOINTS.modifierGroups,
    CATALOG_ENDPOINTS.modifierGroup,
  );

  /** A product's barcodes. */
  barcodes(productId: string): CatalogResource<ProductBarcode, BarcodeCreate, BarcodeUpdate> {
    return this.resource(CATALOG_ENDPOINTS.barcodes(productId), (id) =>
      CATALOG_ENDPOINTS.barcode(productId, id),
    );
  }

  /** A modifier group's modifiers. */
  modifiers(groupId: string): CatalogResource<Modifier, ModifierWrite, ModifierWrite> {
    return this.resource(CATALOG_ENDPOINTS.modifiers(groupId), (id) =>
      CATALOG_ENDPOINTS.modifier(groupId, id),
    );
  }

  private resource<T, C, U>(
    collection: string,
    item: (id: string) => string,
  ): CatalogResource<T, C, U> {
    return {
      list: (params) => this.api.getWithMeta<T[]>(collection, params),
      get: (id) => this.api.get<T>(item(id)),
      create: (body) => this.api.post<T>(collection, body),
      update: (id, body) => this.api.put<T>(item(id), body),
      remove: (id) => this.api.delete<null>(item(id)),
    };
  }
}
