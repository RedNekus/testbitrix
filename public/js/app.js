const { createApp, ref, computed, watch, onMounted } = Vue;

createApp({
  setup() {
    // Реактивные переменные
    const companies = ref([]);
    const total = ref(0);
    const loading = ref(false);
    const error = ref('');
    const currentPage = ref(1);
    const itemsPerPage = 50;
    const progressMessage = ref('');
    const searchQuery = ref('');
    const isFromCache = ref(false);
    const viewMode = ref(localStorage.getItem('viewMode') || 'cards');

    // Сохраняем выбранный режим просмотра
    watch(viewMode, (newMode) => {
      localStorage.setItem('viewMode', newMode);
    });

    // Загрузка данных из кэша при старте
    onMounted(() => {
      setTimeout(loadFromCache, 100);
    });

    // Загрузка из localStorage
    const loadFromCache = () => {
      try {
        const cached = localStorage.getItem('bitrix_companies_cache');
        if (!cached) return;
        
        const cacheData = JSON.parse(cached);
        const now = Date.now();
        
        // Проверка срока годности (10 минут)
        if (now - cacheData.timestamp < 600000) {
          companies.value = cacheData.data?.companies || [];
          total.value = cacheData.data?.total || 0;
          isFromCache.value = true;
          
          // Очистка кэша при отсутствии данных
          if (companies.value.length === 0) {
            clearCache();
          }
        } else {
          localStorage.removeItem('bitrix_companies_cache');
        }
      } catch (e) {
        localStorage.removeItem('bitrix_companies_cache');
      }
    };

    // Сохранение в localStorage
    const saveToCache = () => {
      if (companies.value.length === 0) return;
      
      try {
        const cacheData = {
          data: {  // ← КРИТИЧЕСКИ ВАЖНО: имя свойства перед объектом
            companies: companies.value,
            total: total.value
          },
          timestamp: Date.now()
        };
        
        localStorage.setItem('bitrix_companies_cache', JSON.stringify(cacheData));
      } catch (e) {
        // Очистка при переполнении localStorage
        Object.keys(localStorage).forEach(key => {
          if (key.includes('bitrix')) localStorage.removeItem(key);
        });
      }
    };

    // Очистка кэша
    const clearCache = () => {
      localStorage.removeItem('bitrix_companies_cache');
      companies.value = [];
      total.value = 0;
      isFromCache.value = false;
      error.value = '';
      searchQuery.value = '';
      currentPage.value = 1;
    };

    // Основная функция загрузки данных
    const fetchCompanies = async () => {
      error.value = '';
      loading.value = true;
      progressMessage.value = '';
      searchQuery.value = '';
      isFromCache.value = false;

      try {
        // Безопасный запрос без параметров
        const res = await fetch('/api.php');
        
        if (!res.ok) {
          throw new Error(`HTTP error! status: ${res.status}`);
        }
        
        const data = await res.json();
        
        if (data.error) {
          throw new Error(data.error);
        }
        
        companies.value = data.companies || [];
        total.value = data.total || 0;
        progressMessage.value = `Готово! Загружено ${companies.value.length} компаний.`;
        
        // Сохраняем в кэш
        saveToCache();
      } catch (err) {
        error.value = err.message || 'Неизвестная ошибка при загрузке данных';
        progressMessage.value = '';
      } finally {
        loading.value = false;
      }
    };

    // Поиск и фильтрация
    watch(searchQuery, () => {
      currentPage.value = 1;
    });

    const filteredCompanies = computed(() => {
      if (!searchQuery.value.trim()) return companies.value;
      const query = searchQuery.value.toLowerCase();
      return companies.value.filter(company => 
        (company.TITLE || '').toLowerCase().includes(query)
      );
    });

    // Пагинация
    const totalPages = computed(() => {
      return filteredCompanies.value.length > 0 
        ? Math.ceil(filteredCompanies.value.length / itemsPerPage) 
        : 0;
    });

    const pagedCompanies = computed(() => {
      const start = (currentPage.value - 1) * itemsPerPage;
      return filteredCompanies.value.slice(start, start + itemsPerPage);
    });

    // Вспомогательные функции
    const getFirstValue = (field) => {
      if (!field) return '';
      if (Array.isArray(field) && field.length > 0) {
        return field[0].VALUE || '';
      }
      return field;
    };

    // Экспорт в Excel
    const downloadExcel = () => {
      if (!window.XLSX) {
        alert('Библиотека XLSX не загружена. Обновите страницу.');
        return;
      }

      const data = companies.value.map(company => ({
        ID: company.ID || '',
        Название: company.TITLE || 'Без названия',
        Телефон: getFirstValue(company.PHONE),
        Email: getFirstValue(company.EMAIL),
        Адрес: company.ADDRESS || ''
      }));

      const ws = XLSX.utils.json_to_sheet(data);
      const wb = XLSX.utils.book_new();
      XLSX.utils.book_append_sheet(wb, ws, "Компании");
      XLSX.writeFile(wb, `bitrix24-companies-${new Date().toISOString().slice(0,10)}.xlsx`);
    };

    // Возвращаем все необходимые свойства и методы
    return {
      // Данные
      companies,
      total,
      loading,
      error,
      currentPage,
      totalPages,
      pagedCompanies,
      searchQuery,
      isFromCache,
      viewMode,
      
      // Методы
      fetchCompanies,
      clearCache,
      downloadExcel,
      getFirstValue,
      
      // Вспомогательные
      progressMessage,
      filteredCompanies
    };
  }
}).mount('#app');