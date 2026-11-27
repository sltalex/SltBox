typedef unsigned long long size_t;
typedef int int32_t;

extern void *__stdcall GetModuleHandleW(const unsigned short *name);
extern void *__stdcall LoadLibraryW(const unsigned short *name);
extern void *__stdcall GetProcAddress(void *module, const char *name);
extern void *__stdcall _InterlockedCompareExchangePointer(void *volatile *destination, void *exchange, void *comparand);

typedef void *(__stdcall *PFN_CoTaskMemAlloc)(size_t size);
typedef void (__stdcall *PFN_CoTaskMemFree)(void *pointer);
typedef int32_t (__stdcall *PFN_CoCreateFreeThreadedMarshaler)(void *outer, void **marshaler);

static void *get_ole32_module(void) {
    static void *volatile cached = 0;
    void *module = cached;
    if (!module) {
        module = GetModuleHandleW(L"ole32.dll");
        if (!module) module = LoadLibraryW(L"ole32.dll");
        if (module) _InterlockedCompareExchangePointer(&cached, module, 0);
    }
    return module;
}

static void *__stdcall shim_CoTaskMemAlloc(size_t size) {
    static void *volatile cached = 0;
    PFN_CoTaskMemAlloc function = (PFN_CoTaskMemAlloc)cached;
    if (!function) {
        void *module = get_ole32_module();
        if (module) {
            function = (PFN_CoTaskMemAlloc)GetProcAddress(module, "CoTaskMemAlloc");
            if (function) _InterlockedCompareExchangePointer(&cached, (void *)function, 0);
        }
    }
    return function ? function(size) : 0;
}

static void __stdcall shim_CoTaskMemFree(void *pointer) {
    static void *volatile cached = 0;
    PFN_CoTaskMemFree function = (PFN_CoTaskMemFree)cached;
    if (!function) {
        void *module = get_ole32_module();
        if (module) {
            function = (PFN_CoTaskMemFree)GetProcAddress(module, "CoTaskMemFree");
            if (function) _InterlockedCompareExchangePointer(&cached, (void *)function, 0);
        }
    }
    if (function) function(pointer);
}

static int32_t __stdcall shim_CoCreateFreeThreadedMarshaler(void *outer, void **marshaler) {
    static void *volatile cached = 0;
    PFN_CoCreateFreeThreadedMarshaler function = (PFN_CoCreateFreeThreadedMarshaler)cached;
    if (!function) {
        void *module = get_ole32_module();
        if (module) {
            function = (PFN_CoCreateFreeThreadedMarshaler)GetProcAddress(module, "CoCreateFreeThreadedMarshaler");
            if (function) _InterlockedCompareExchangePointer(&cached, (void *)function, 0);
        }
    }
    return function ? function(outer, marshaler) : (int32_t)0x80004001u;
}

void *__stdcall CoTaskMemAlloc(size_t size) {
    return shim_CoTaskMemAlloc(size);
}

void __stdcall CoTaskMemFree(void *pointer) {
    shim_CoTaskMemFree(pointer);
}

int32_t __stdcall CoCreateFreeThreadedMarshaler(void *outer, void **marshaler) {
    return shim_CoCreateFreeThreadedMarshaler(outer, marshaler);
}

const void *const __imp_CoTaskMemAlloc = (const void *)shim_CoTaskMemAlloc;
const void *const __imp_CoTaskMemFree = (const void *)shim_CoTaskMemFree;
const void *const __imp_CoCreateFreeThreadedMarshaler = (const void *)shim_CoCreateFreeThreadedMarshaler;
