const { createApp, ref, computed, watch, onMounted } = Vue;

createApp({
  setup() {
    const webhookUrl = ref('');
    const companies = ref([]);
    const total = ref(0);
    const loading = ref(false);
    const error = ref('');
    const currentPage = ref(1);
    const itemsPerPage = 50;
    const progressMessage = ref('');
    const searchQuery = ref('');
    const isFromCache = ref(false);
	const viewMode = ref('cards'); // 'cards' или 'table'

    // Загружаем кэш после полной инициализации Vue
    onMounted(() => {
      setTimeout(loadFromCache, 100);
    });

    // Загрузка данных из кэша
    const loadFromCache = () => {
      try {
        const cached = localStorage.getItem('bitrix_companies_cache');
        if (!cached) return;

        const cacheData = JSON.parse(cached);
        const now = Date.now();
        
        // Проверяем срок годности (10 минут = 600000 мс)
        if (now - cacheData.timestamp < 600000) {
          // Восстанавливаем URL и данные
          webhookUrl.value = cacheData.url || '';
          companies.value = cacheData.data?.companies || [];
          total.value = cacheData.data?.total || 0;
          isFromCache.value = true;
          
          console.log('✅ Данные загружены из кэша');
          
          // Если данных нет — очищаем кэш
          if (companies.value.length === 0) {
            clearCache();
          }
        } else {
          localStorage.removeItem('bitrix_companies_cache');
          console.log('🕒 Кэш устарел и удалён');
        }
      } catch (e) {
        console.error('❌ Ошибка при загрузке кэша', e);
        localStorage.removeItem('bitrix_companies_cache');
      }
    };

    // Сохранение данных в кэш
    const saveToCache = () => {
      if (!webhookUrl.value.trim() || companies.value.length === 0) return;
      
      try {
        const cacheData = {
          data: {
            companies: companies.value,
            total: total.value
          },
          timestamp: Date.now(),
          url: webhookUrl.value.trim()
        };
        
        localStorage.setItem('bitrix_companies_cache', JSON.stringify(cacheData));
        console.log('💾 Данные сохранены в кэш');
      } catch (e) {
        console.error('❌ Ошибка при сохранении кэша', e);
        // Очищаем ВЕСЬ кэш Bitrix при переполнении
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
      webhookUrl.value = '';
      isFromCache.value = false;
      error.value = '';
      searchQuery.value = '';
      currentPage.value = 1;
      console.log('🧹 Кэш очищен');
    };

    // Валидация URL вебхука
    const validateWebhookUrl = () => {
      const url = webhookUrl.value.trim();
      if (!url) return 'Введите URL вебхука';
      
      try {
        new URL(url);
      } catch (e) {
        return 'Некорректный формат URL. Пример: https://yoow.bitrix24.by/rest/123/токен/crm.company.list.json';
      }

      // Проверяем путь
      if (!url.includes('/crm.company.list.json')) {
        return 'URL должен содержать `/crm.company.list.json` в пути. Пример: https://yoow.bitrix24.by/rest/123/токен/crm.company.list.json';
      }

      // Проверяем домен (гибкий подход)
      const isBitrixDomain = url.includes('bitrix24.') || 
                           url.includes('/bitrix/') || 
                           url.includes('/rest/');
      
      if (!isBitrixDomain) {
        return 'Подозрительный домен. Убедитесь, что это Bitrix24: URL должен содержать "bitrix24" или "/rest/". Пример: https://ваш-портал.bitrix24.by/rest/...';
      }

      return null;
    };

    // Реактивные вычисления
    watch(searchQuery, () => {
      currentPage.value = 1;
    });

    watch(companies, () => {
      currentPage.value = 1;
      searchQuery.value = '';
    });

    const filteredCompanies = computed(() => {
      if (!searchQuery.value.trim()) return companies.value;
      const query = searchQuery.value.toLowerCase();
      return companies.value.filter(company => 
        (company.TITLE || '').toLowerCase().includes(query)
      );
    });

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

    // Основная функция загрузки
    const fetchCompanies = async () => {
      const validationError = validateWebhookUrl();
      if (validationError) {
        error.value = validationError;
        return;
      }

      error.value = '';
      loading.value = true;
      progressMessage.value = '';
      searchQuery.value = '';
      isFromCache.value = false;

      let start = 0;
      let allCompanies = [];
      const maxCompanies = 10000;
      let requestCount = 0;
      const maxRequests = Math.ceil(maxCompanies / 50);

      try {
        while (allCompanies.length < maxCompanies) {
          requestCount++;
          const percent = Math.min(100, Math.floor((requestCount / maxRequests) * 100));
          progressMessage.value = `Загружено ${requestCount} из ~${maxRequests} страниц (${percent}%)...`;

          const controller = new AbortController();
          const timeoutId = setTimeout(() => controller.abort(), 50000);

          const res = await fetch('/api.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              webhookUrl: webhookUrl.value,
              postData: {
                start: start,
                select: ['ID', 'TITLE', 'PHONE', 'EMAIL', 'ADDRESS', 'DATE_CREATE']
              }
            }),
            signal: controller.signal
          });

          clearTimeout(timeoutId);

          if (!res.ok) {
            let errorMsg = `Сервер вернул ошибку ${res.status}`;
            try {
              const data = await res.json();
              if (data.error) errorMsg = data.error;
            } catch (e) {
              // Игнорируем ошибку парсинга
            }
            throw new Error(errorMsg);
          }

          const data = await res.json();

          if (data.error) {
            throw new Error(data.error);
          }

          if (!data.result || !Array.isArray(data.result)) {
            if (allCompanies.length === 0) {
              throw new Error('Bitrix24 вернул пустой результат. Возможно, у вас нет компаний или закончились права.');
            }
            break;
          }

          allCompanies = allCompanies.concat(data.result);
          if (allCompanies.length >= maxCompanies) {
            allCompanies = allCompanies.slice(0, maxCompanies);
            break;
          }

          start = data.next ?? null;
          if (start === null) break;

          await new Promise(r => setTimeout(r, 50));
        }

        if (allCompanies.length === 0) {
          error.value = 'Не найдено ни одной компании. Проверьте права вебхука или наличие данных в Bitrix24.';
          loading.value = false;
          return;
        }

        companies.value = allCompanies;
        total.value = allCompanies.length;
        progressMessage.value = `Готово! Загружено ${allCompanies.length} компаний.`;
        
        // Сохраняем в кэш
        saveToCache();
      } catch (err) {
        if (err.name === 'AbortError') {
          error.value = 'Запрос прерван из-за таймаута (50 секунд). Bitrix24 не ответил вовремя. Попробуйте уменьшить объём данных.';
        } else {
          error.value = err.message || 'Неизвестная ошибка при загрузке данных';
        }
        progressMessage.value = '';
      } finally {
        loading.value = false;
      }
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

    // Форматирование ошибок с гиперссылками
    const formattedError = computed(() => {
      let msg = error.value;
      
      if (msg.includes('Настройки → REST API')) {
        msg = msg.replace(
          'Настройки → REST API', 
          '<a href="https://helpdesk.bitrix24.ru/open/11229918/" target="_blank">Настройки → REST API</a>'
        );
      }
      
      if (msg.includes('https://status.bitrix24.ru')) {
        msg = msg.replace(
          'https://status.bitrix24.ru',
          '<a href="https://status.bitrix24.ru" target="_blank">статус сервисов Bitrix24</a>'
        );
      }
      
      return msg;
    });

    return {
      webhookUrl,
      companies,
      total,
      loading,
      error,
      currentPage,
      totalPages,
      pagedCompanies,
      getFirstValue,
      downloadExcel,
      progressMessage,
      searchQuery,
      filteredCompanies,
      isFromCache,
      clearCache,
      fetchCompanies,
      formattedError,
      validateWebhookUrl,
	  viewMode
    };
  }
}).mount('#app');