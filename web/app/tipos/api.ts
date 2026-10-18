// ---------------------------------------------------------------------------
// Tipos que viajan entre la interfaz y la API.
// ---------------------------------------------------------------------------
//
// Son una COPIA de lo que devuelve la API, escrita a mano. Podrian
// generarse desde OpenAPI, pero hoy no hay ningun endpoint que lo exponga, y
// inventar el comando "para generarlo despues" seria prometer algo que no
// existe. Cuando exista, estos tipos se reemplazan por los generados y esta
// nota se borra.
//
// La regla: si un tipo y la API no coinciden, el problema esta en el tipo.
// Ver docs/09-API.md.
//
// LA REGLA DE `null` EN ESTOS TIPOS
// --------------------------------
// Aqui `null` casi nunca es "falta un dato". En una vendimia, `brix: null`
// significa "NO SE MIDIO", que es un hecho distinto de `brix: 0`, que
// significa "se midio y dio cero". La interfaz tiene que distinguirlos, y
// por eso los dos casos son tipos distintos y no un numero opcional.
// ---------------------------------------------------------------------------

/** Quien esta operando. Lo devuelve `GET /api/v1/yo`. */
export interface Usuario {
  id: string;
  email: string;
  nombre: string;
  rol: 'auditor' | 'piso' | 'bodega' | 'encargado' | 'administrador';
  vina?: string | null;

  puede_vendimiar: boolean;
  puede_vinificar: boolean;
  puede_editar_campo: boolean;
  puede_vender: boolean;
  puede_descartar_lote: boolean;
  puede_anular: boolean;
  puede_administrar: boolean;
}

/** Una viña del selector de acceso. */
export interface VinaPublica {
  slug: string;
  nombre: string;
}

/** Un bloque del viñedo. Es el origen de toda la trazabilidad. */
export interface Parcela {
  id: string;
  codigo: string;
  nombre: string;
  variedad: string;
  superficie_hectareas: number | null;
  ano_plantacion: number | null;
  comuna: string | null;
  activo: boolean;
  vendimias: number | null;
}

/** La uva de una parcela, en una fecha. La fecha es el origen del vino. */
export interface Vendimia {
  id: string;
  numero: string;
  parcela_id: string;
  parcela: string | null;
  variedad: string | null;
  /** LA fecha que responde "de que vendimia viene este vino". */
  fecha_vendimia: string | null;
  kilos: number;
  /** `null` significa NO MEDIDO. Un cero seria un dato falso. */
  brix: number | null;
  /** `null` significa NO MEDIDO. Un cero seria un dato falso. */
  acidez: number | null;
  tiene_brix: boolean;
  tiene_acidez: boolean;
  estado: 'cosechada' | 'en_bodega' | 'descartada';
  lote_id: string | null;
  lote_codigo: string | null;
  notas: string | null;
}

/** El vino que sale de una vendimia. */
export interface Lote {
  id: string;
  codigo: string;
  nombre: string | null;
  anio: number;
  estado: 'recepcion' | 'en_vinificacion' | 'estabilizado' | 'embotellado' | 'descartado';
  litros: number;
  vendimia_id: string;
  fecha_vendimia: string | null;
  parcela: string | null;
  variedad: string | null;
  /** "Litoral - Cabernet Sauvignon, vendimia del 2024-03-18". `null` si la cadena esta rota. */
  linea_de_origen: string | null;
  etapas: Etapa[];
  embotellados: EmbotelladoResumido[];
}

/** Una etapa que el lote YA paso. No se edita y no se borra. */
export interface Etapa {
  etapa: string;
  etiqueta: string;
  fecha: string | null;
  responsable: string | null;
  notas: string | null;
}

export interface EmbotelladoResumido {
  id: string;
  fecha_embotellado: string | null;
  formato_ml: number;
  botellas: number;
}

/** Lo que el siguiente paso del proceso puede ser. */
export interface SiguienteEtapa {
  etapa: string;
  etiqueta: string;
}

/** La respuesta de registrar una etapa. */
export interface RespuestaDeEtapa {
  etapa: Etapa;
  lote: Lote;
  /** `null` cuando ya completo todo lo obligatorio. */
  siguiente_etapa: SiguienteEtapa | null;
  opcionales_disponibles: SiguienteEtapa[];
}

/** El ultimo eslabon: el vino en botella. */
export interface Embotellado {
  id: string;
  fecha_embotellado: string | null;
  botellas: number;
  formato_ml: number;
}

/**
 * LA RESPUESTA DE LA TRAZABILIDAD.
 *
 * `completa: false` NO es un error: es la respuesta honesta cuando a la cadena
 * le falta un eslabon. La interfaz tiene que decirlo, no esconderlo, porque
 * un origen que se inventa es peor que un origen que falta.
 */
export interface Trazabilidad {
  embotellado?: Embotellado | null;
  lote: LoteTrazado | null;
  vendimia: VendimiaTrazada | null;
  parcela: ParcelaTrazada | null;
  /** `false` cuando falta algun eslabon. */
  completa: boolean;
  /** Texto para la etiqueta. `null` cuando la cadena esta rota. */
  linea_de_origen: string | null;
}

export interface LoteTrazado {
  id: string;
  codigo: string;
  nombre: string | null;
  anio: number;
  estado: string;
  litros: number;
}

export interface VendimiaTrazada {
  id: string;
  numero: string;
  fecha_vendimia: string | null;
  kilos: number;
  brix: number | null;
  acidez: number | null;
  estado: string;
}

export interface ParcelaTrazada {
  id: string;
  codigo: string;
  nombre: string;
  variedad: string;
  comuna: string | null;
  superficie_hectareas: number | null;
  ano_plantacion: number | null;
}

/** La trazabilidad de una parcela: que se hizo con ella. */
export interface TrazabilidadDeParcela {
  parcela: {
    id: string;
    codigo: string;
    nombre: string;
    variedad: string;
    comuna: string | null;
  };
  lotes: Array<{
    id: string;
    codigo: string;
    nombre: string | null;
    anio: number;
    estado: string;
    litros: number;
    fecha_vendimia: string | null;
  }>;
  vendimias: Array<{
    id: string;
    numero: string;
    fecha_vendimia: string | null;
    kilos: number;
    brix: number | null;
    acidez: number | null;
    estado: string;
    tiene_lote: boolean;
    lote_codigo: string | null;
  }>;
}

/** Una venta de vino embotellado. */
export interface Venta {
  id: string;
  numero: string;
  estado: 'abierta' | 'pagada' | 'anulada';
  total: number;
  abierta_en: string | null;
  cerrada_en: string | null;
  minutos_abierta: number;
  cliente_nombre: string | null;
  cliente_contacto: string | null;
  items: ItemDeVenta[];
  pagos: Pago[];
  notas: string | null;
}

export interface ItemDeVenta {
  embotellado_id: string;
  botellas: number;
  precio_unitario: number;
  subtotal: number;
  lote_codigo: string | null;
  /** La trazabilidad viaja CON la venta. Es lo que permite responder meses después. */
  linea_de_origen: string | null;
}

export interface Pago {
  metodo: 'efectivo' | 'tarjeta' | 'transferencia';
  monto: number;
  pagado_en: string | null;
}

/** Las botellas que quedan de un embotellado. */
export interface Disponibles {
  embotellado_id: string;
  botellas_totales: number;
  botellas_disponibles: number;
  formato_ml: number;
  lote_codigo: string | null;
  linea_de_origen: string | null;
}

/** El resumen de la temporada. */
export interface ResumenDeConfiguracion {
  anio: number;
  campo: {
    parcelas_activas: number;
    variedades: number;
  };
  vendimia: {
    vendimias: number;
    kilos_totales: number;
    descartadas: number;
    con_brix: number;
    /** `null` cuando no hay ninguna vendimia con analisis. */
    brix_promedio: number | null;
    con_acidez: number;
    acidez_promedio: number | null;
  };
  lotes: {
    lotes: number;
    embotellados: number;
    /** Los lotes que se perdieron. Que sean visibles es el punto. */
    descartados: number;
    litros_totales: number;
    litros_perdidos: number;
    botellas_embotelladas: number;
  };
  ventas: {
    ventas: number;
    pagadas: number;
    anuladas: number;
    abiertas: number;
    total_vendido: number;
  };
}

/** Los errores que devuelve la API, con su codigo estable. */
export interface ErrorDeApi {
  mensaje: string;
  codigo?: string;
}